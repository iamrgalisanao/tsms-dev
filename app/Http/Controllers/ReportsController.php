<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class ReportsController extends Controller
{
    /**
     * Display a simple reports landing page for finance users.
     */
    public function index(Request $request)
    {
        // Finance dashboard - loads interactive widgets that call the Webapp API
        // Provide tenant list and selected tenant for the view
        $tenants = Tenant::orderBy('trade_name')->get(['id', 'trade_name'])->pluck('trade_name', 'id')->toArray();
        $selected_tenant = $request->get('tenant', '');

        // Render the finance reports dashboard view. Use the consolidated
        // `reports.dashboard` view which contains the Finance Reports UI and
        // shared chart/table scripts (resources/views/reports/dashboard.blade.php).
        return view('reports.dashboard', compact('tenants', 'selected_tenant'));
    }

    /**
     * JSON endpoint returning daily totals for a given tenant and month.
     * Returns shape expected by the reports dashboard JS.
     */
    public function data(Request $request)
    {
        $tenantId = $request->query('tenant', $request->query('trade', null));
        $rawMonth = $request->query('month', now()->format('Y-m'));

        try {
            $monthDate = Carbon::parse($rawMonth . '-01');
        } catch (\Throwable $e) {
            $monthDate = now()->startOfMonth();
        }

        $startDate = $monthDate->copy()->startOfMonth()->toDateString();
        $endDate = $monthDate->copy()->endOfMonth()->toDateString();
        $year = $monthDate->year;
        $month = $monthDate->format('m');

        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);
        $reportDateExpr = Schema::hasColumn('transactions', 'transaction_date')
            ? 'transaction_date'
            : 'DATE(transaction_timestamp)';
        $joinedReportDateExpr = Schema::hasColumn('transactions', 'transaction_date')
            ? 'transactions.transaction_date'
            : 'DATE(transactions.transaction_timestamp)';

        // 1. Optimized Main Transaction Aggregation
        $query = Transaction::query()
            ->selectRaw("
                {$reportDateExpr} as report_date,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                SUM(vatable_sales) as vatable_sales,
                SUM(sc_vat_exempt_sales) as sc_vat_exempt_sales,
                SUM(vat_amount) as vat_amount,
                SUM(promo_discount) as promo_discount,
                SUM(senior_discount) as senior_discount,
                SUM(pwd_discount) as pwd_discount,
                SUM(discount_total) as regular_discount,
                SUM(service_charge) as service_charge_distributed,
                SUM(management_service_charge) as service_charge_retained,
                SUM(IF(promo_status = 'WITH_APPROVAL', promo_discount, 0)) as promo_with_approval,
                SUM(IF(promo_status != 'WITH_APPROVAL', promo_discount, 0)) as promo_without_approval
            ")
            ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantId && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        if ($excludeVoids) {
            $query->where('transaction_type', '!=', 'VOID')
                  ->whereNull('voided_at');
        }

        $dailyMain = $query->groupBy('report_date')->get()->keyBy('report_date');

        // 2. Fetch Adjustments Aggregates (Daily)
        $adjQuery = \DB::table('transaction_adjustments')
            ->join('transactions', 'transaction_adjustments.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$joinedReportDateExpr} as report_date,
                SUM(IF(transaction_adjustments.adjustment_type IN ('employee_discount', 'EMPLOYEE'), transaction_adjustments.amount, 0)) as employee_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('vip_card_discount', 'VIP'), transaction_adjustments.amount, 0)) as vip_discount
            ")
            ->whereRaw("{$joinedReportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantId && $tenantId !== 'all') {
            $adjQuery->where('transactions.tenant_id', $tenantId);
        }
        if ($excludeVoids) {
            $adjQuery->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }
        $dailyAdj = $adjQuery->groupBy('report_date')->get()->keyBy('report_date');

        // 3. Fetch Taxes Aggregates (Daily)
        $taxQuery = \DB::table('transaction_taxes')
            ->join('transactions', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$joinedReportDateExpr} as report_date,
                SUM(IF(transaction_taxes.tax_type IN ('SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT'), transaction_taxes.amount, 0)) as sc_vat_exempt_fallback,
                SUM(IF(transaction_taxes.tax_type NOT IN ('VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT', 'VATEXEMPT_SALES', 'VAT_EXEMPT_SALES', 'ZERO_RATED', 'NON-VAT', 'NON_VAT', 'ZERO-RATED'), transaction_taxes.amount, 0)) as other_tax_basis
            ")
            ->whereRaw("{$joinedReportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantId && $tenantId !== 'all') {
            $taxQuery->where('transactions.tenant_id', $tenantId);
        }
        if ($excludeVoids) {
            $taxQuery->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }
        $dailyTax = $taxQuery->groupBy('report_date')->get()->keyBy('report_date');

        $service = app(\App\Services\Reports\FinanceCalculationService::class);

        // Convert the SQL objects back to basic arrays for consistent processing
        $dailyTotals = [];
        $allComponents = [];

        // We use the union of all dates present in the results
        $allDates = $dailyMain->keys()->union($dailyAdj->keys())->union($dailyTax->keys())->sort();

        foreach ($allDates as $date) {
            $tx = $dailyMain->get($date);
            $adj = $dailyAdj->get($date);
            $tax = $dailyTax->get($date);

            $components = [
                'vatable_sales' => (float)($tx->vatable_sales ?? 0),
                'sc_vat_exempt_sales' => (float)($tx->sc_vat_exempt_sales ?? 0),
                'vat_amount' => (float)($tx->vat_amount ?? 0),
                'promo_with_approval' => (float)($tx->promo_with_approval ?? 0),
                'promo_without_approval' => (float)($tx->promo_without_approval ?? 0),
                'employee_discount' => (float)($adj->employee_discount ?? 0),
                'senior_discount' => (float)($tx->senior_discount ?? 0),
                'pwd_discount' => (float)($tx->pwd_discount ?? 0),
                'vip_discount' => (float)($adj->vip_discount ?? 0),
                'other_tax' => (float)($tax->other_tax_basis ?? 0),
                'service_charge_distributed' => (float)($tx->service_charge_distributed ?? 0),
                'service_charge_retained' => (float)($tx->service_charge_retained ?? 0),
                // CSMR-style views do not have a standalone regular discount column.
                // Excluding discount_total avoids hidden double-counting in gross math.
                'regular_discount' => 0.0,
                'gross_sales' => (float)($tx->gross_sales ?? 0),
                'net_sales' => (float)($tx->net_sales ?? 0),
            ];

            // Parity Check: If main column sc_vat_exempt_sales is 0, use the tax fallback
            if ($components['sc_vat_exempt_sales'] === 0.0 && isset($tax->sc_vat_exempt_fallback)) {
                $components['sc_vat_exempt_sales'] = (float)$tax->sc_vat_exempt_fallback;
            }

            // Add to month-wide aggregate components
            foreach ($components as $key => $val) {
                $allComponents[$key] = ($allComponents[$key] ?? 0) + $val;
            }

            $derived = $service->deriveMetrics($components, ['gross_sales_basis' => 'pre_deduction']);
            $dailyTotals[$date] = $derived;
        }

        // Build total month metrics
        $totals = $service->deriveMetrics($allComponents, ['gross_sales_basis' => 'pre_deduction']);

        return response()->json([
            'status' => 'success',
            'year' => (int)$year,
            'month' => $month,
            'daily_totals' => $dailyTotals,
            'totals' => $totals,
        ]);
    }
}
