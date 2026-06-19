<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        $reportDateExpr = $this->localReportDateExpression('COALESCE(transaction_timestamp, created_at)');
        $joinedReportDateExpr = $this->localReportDateExpression('COALESCE(transactions.transaction_timestamp, transactions.created_at)');

        if ($summaryPayload = $this->dailySummaryPayload($startDate, $endDate, $tenantId, $year, $month)) {
            return response()->json($summaryPayload);
        }

        $grossExpr = Schema::hasColumn('transactions', 'gross_sales') ? 'SUM(gross_sales)' : '0';
        $netExpr = Schema::hasColumn('transactions', 'net_sales') ? 'SUM(net_sales)' : '0';
        $vatableExpr = Schema::hasColumn('transactions', 'vatable_sales') ? 'SUM(vatable_sales)' : '0';
        $scVatExpr = Schema::hasColumn('transactions', 'sc_vat_exempt_sales') ? 'SUM(sc_vat_exempt_sales)' : '0';
        $vatExpr = Schema::hasColumn('transactions', 'vat_amount') ? 'SUM(vat_amount)' : '0';
        $promoWithExpr = (Schema::hasColumn('transactions', 'promo_discount') && Schema::hasColumn('transactions', 'promo_status'))
            ? "SUM(IF(promo_status = 'WITH_APPROVAL', promo_discount, 0))"
            : '0';
        $promoWithoutExpr = Schema::hasColumn('transactions', 'promo_discount')
            ? (Schema::hasColumn('transactions', 'promo_status')
                ? "SUM(IF(promo_status != 'WITH_APPROVAL' OR promo_status IS NULL, promo_discount, 0))"
                : 'SUM(promo_discount)')
            : '0';
        $seniorExpr = Schema::hasColumn('transactions', 'senior_discount') ? 'SUM(senior_discount)' : '0';
        $pwdExpr = Schema::hasColumn('transactions', 'pwd_discount') ? 'SUM(pwd_discount)' : '0';
        $regularExpr = Schema::hasColumn('transactions', 'discount_total') ? 'SUM(discount_total)' : '0';
        $serviceChargeExpr = Schema::hasColumn('transactions', 'service_charge') ? 'SUM(service_charge)' : '0';
        $managementServiceChargeExpr = Schema::hasColumn('transactions', 'management_service_charge') ? 'SUM(management_service_charge)' : '0';

        // 1. Optimized Main Transaction Aggregation
        $query = Transaction::query()
            ->selectRaw("
                {$reportDateExpr} as report_date,
                {$grossExpr} as gross_sales,
                {$netExpr} as net_sales,
                {$vatableExpr} as vatable_sales,
                {$scVatExpr} as sc_vat_exempt_sales,
                {$vatExpr} as vat_amount,
                {$seniorExpr} as senior_discount,
                {$pwdExpr} as pwd_discount,
                {$regularExpr} as regular_discount,
                {$serviceChargeExpr} as service_charge_distributed,
                {$managementServiceChargeExpr} as service_charge_retained,
                {$promoWithExpr} as promo_with_approval,
                {$promoWithoutExpr} as promo_without_approval
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
        $dailyPayloadAdjustments = $this->payloadAdjustmentTotals($startDate, $endDate, $tenantId, $reportDateExpr, $service);

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
                'promo_with_approval' => max((float)($tx->promo_with_approval ?? 0), (float)($dailyPayloadAdjustments[$date]['promo_with_approval'] ?? 0)),
                'promo_without_approval' => max((float)($tx->promo_without_approval ?? 0), (float)($dailyPayloadAdjustments[$date]['promo_without_approval'] ?? 0)),
                'employee_discount' => max((float)($adj->employee_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['employee_discount'] ?? 0)),
                'senior_discount' => max((float)($tx->senior_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['senior_discount'] ?? 0)),
                'pwd_discount' => max((float)($tx->pwd_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['pwd_discount'] ?? 0)),
                'vip_discount' => max((float)($adj->vip_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['vip_discount'] ?? 0)),
                'other_tax' => (float)($tax->other_tax_basis ?? 0),
                'service_charge_distributed' => max((float)($tx->service_charge_distributed ?? 0), (float)($dailyPayloadAdjustments[$date]['service_charge_distributed'] ?? 0)),
                'service_charge_retained' => max((float)($tx->service_charge_retained ?? 0), (float)($dailyPayloadAdjustments[$date]['service_charge_retained'] ?? 0)),
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
            'source' => 'raw_transactions',
        ]);
    }

    private function dailySummaryPayload(string $startDate, string $endDate, $tenantId, int $year, string $month): ?array
    {
        if (! Schema::hasTable('daily_transaction_summaries') || ! Schema::hasTable('report_refresh_states')) {
            return null;
        }

        if (! $this->hasCompleteDailySummaryRefresh($startDate, $endDate, $tenantId)) {
            return null;
        }

        $rows = DB::table('daily_transaction_summaries')
            ->selectRaw('business_date as report_date')
            ->selectRaw('SUM(gross_sales) as gross_sales')
            ->selectRaw('SUM(net_sales) as net_sales')
            ->selectRaw('SUM(vatable_sales) as vatable_sales')
            ->selectRaw('SUM(sc_vat_exempt_sales) as sc_vat_exempt_sales')
            ->selectRaw('SUM(vat_amount) as vat_amount')
            ->selectRaw('SUM(promo_with_approval) as promo_with_approval')
            ->selectRaw('SUM(promo_without_approval) as promo_without_approval')
            ->selectRaw('SUM(employee_discount) as employee_discount')
            ->selectRaw('SUM(senior_discount) as senior_discount')
            ->selectRaw('SUM(pwd_discount) as pwd_discount')
            ->selectRaw('SUM(vip_discount) as vip_discount')
            ->selectRaw('SUM(other_tax) as other_tax')
            ->selectRaw('SUM(service_charge_distributed) as service_charge_distributed')
            ->selectRaw('SUM(service_charge_retained) as service_charge_retained')
            ->whereBetween('business_date', [$startDate, $endDate])
            ->when($tenantId && $tenantId !== 'all', fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->keyBy('report_date');

        $service = app(\App\Services\Reports\FinanceCalculationService::class);
        $reportDateExpr = $this->localReportDateExpression('COALESCE(transaction_timestamp, created_at)');
        $payloadAdjustments = $this->payloadAdjustmentTotals($startDate, $endDate, $tenantId, $reportDateExpr, $service);
        $dailyTotals = [];
        $allComponents = [];

        foreach ($rows as $date => $row) {
            $components = [
                'vatable_sales' => (float) ($row->vatable_sales ?? 0),
                'sc_vat_exempt_sales' => (float) ($row->sc_vat_exempt_sales ?? 0),
                'vat_amount' => (float) ($row->vat_amount ?? 0),
                'promo_with_approval' => max((float) ($row->promo_with_approval ?? 0), (float) ($payloadAdjustments[$date]['promo_with_approval'] ?? 0)),
                'promo_without_approval' => max((float) ($row->promo_without_approval ?? 0), (float) ($payloadAdjustments[$date]['promo_without_approval'] ?? 0)),
                'employee_discount' => max((float) ($row->employee_discount ?? 0), (float) ($payloadAdjustments[$date]['employee_discount'] ?? 0)),
                'senior_discount' => max((float) ($row->senior_discount ?? 0), (float) ($payloadAdjustments[$date]['senior_discount'] ?? 0)),
                'pwd_discount' => max((float) ($row->pwd_discount ?? 0), (float) ($payloadAdjustments[$date]['pwd_discount'] ?? 0)),
                'vip_discount' => max((float) ($row->vip_discount ?? 0), (float) ($payloadAdjustments[$date]['vip_discount'] ?? 0)),
                'other_tax' => (float) ($row->other_tax ?? 0),
                'service_charge_distributed' => max((float) ($row->service_charge_distributed ?? 0), (float) ($payloadAdjustments[$date]['service_charge_distributed'] ?? 0)),
                'service_charge_retained' => max((float) ($row->service_charge_retained ?? 0), (float) ($payloadAdjustments[$date]['service_charge_retained'] ?? 0)),
                'regular_discount' => 0.0,
                'gross_sales' => (float) ($row->gross_sales ?? 0),
                'net_sales' => (float) ($row->net_sales ?? 0),
            ];

            foreach ($components as $key => $value) {
                $allComponents[$key] = ($allComponents[$key] ?? 0) + $value;
            }

            $dailyTotals[$date] = $service->deriveMetrics($components, ['gross_sales_basis' => 'pre_deduction']);
        }

        return [
            'status' => 'success',
            'year' => $year,
            'month' => $month,
            'daily_totals' => $dailyTotals,
            'totals' => $service->deriveMetrics($allComponents, ['gross_sales_basis' => 'pre_deduction']),
            'source' => 'daily_transaction_summaries',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function payloadAdjustmentTotals(string $startDate, string $endDate, $tenantId, string $reportDateExpr, \App\Services\Reports\FinanceCalculationService $service): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $rows = DB::table('transactions')
            ->selectRaw($reportDateExpr . ' as report_date')
            ->addSelect('promo_status', 'original_payload')
            ->whereRaw($reportDateExpr . ' BETWEEN ? AND ?', [$startDate, $endDate])
            ->when($tenantId && $tenantId !== 'all', fn ($query) => $query->where('tenant_id', $tenantId));

        if (config('tsms.reporting.exclude_voids_from_totals', true)) {
            $rows->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }

        $totals = [];
        foreach ($rows->cursor() as $row) {
            $date = (string) $row->report_date;
            $totals[$date] ??= [
                'promo_with_approval' => 0.0,
                'promo_without_approval' => 0.0,
                'employee_discount' => 0.0,
                'senior_discount' => 0.0,
                'pwd_discount' => 0.0,
                'vip_discount' => 0.0,
                'service_charge_distributed' => 0.0,
                'service_charge_retained' => 0.0,
            ];

            foreach ($service->adjustmentComponentsFromPayload($row->original_payload, $row->promo_status) as $key => $value) {
                $totals[$date][$key] += $value;
            }
        }

        return $totals;
    }

    private function localReportDateExpression(string $timestampExpression): string
    {
        $offsetMinutes = Carbon::now($this->reportTimezone())->utcOffset();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $modifier = sprintf('%+d minutes', $offsetMinutes);

            return "DATE(datetime({$timestampExpression}, '{$modifier}'))";
        }

        if ($driver === 'pgsql') {
            $operator = $offsetMinutes >= 0 ? '+' : '-';
            $minutes = abs($offsetMinutes);

            return "DATE({$timestampExpression} {$operator} INTERVAL '{$minutes} minutes')";
        }

        $function = $offsetMinutes >= 0 ? 'DATE_ADD' : 'DATE_SUB';
        $minutes = abs($offsetMinutes);

        return "DATE({$function}({$timestampExpression}, INTERVAL {$minutes} MINUTE))";
    }

    private function reportTimezone(): string
    {
        return config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila';
    }

    private function hasCompleteDailySummaryRefresh(string $startDate, string $endDate, $tenantId): bool
    {
        $expectedDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $query = DB::table('report_refresh_states')
            ->where('report_type', 'daily_transaction_summaries')
            ->where('status', 'completed')
            ->whereBetween('business_date', [$startDate, $endDate]);

        if ($tenantId && $tenantId !== 'all') {
            $query->where(function ($nested) use ($tenantId) {
                $nested->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
        } else {
            $query->whereNull('tenant_id');
        }

        return (int) $query->distinct('business_date')->count('business_date') >= $expectedDays;
    }
}
