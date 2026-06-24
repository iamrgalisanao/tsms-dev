<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Tenant;
use App\Models\AuditLog;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesReportExportController extends Controller
{
    /**
     * Export the Certified Monthly Sales Report (CMSR) as an Excel file.
     * Uses native PHP headers for maximum compatibility with all browsers.
     */
    public function export(Request $request)
    {
        // 1) Sanitize inputs
        $yearInput = $request->query('year');
        $monthInput = $request->query('month');
        $tenantId = $request->query('tenant', null);

        $year = (int) ($yearInput ?: now()->year);
        $monthStr = str_pad($monthInput ?: now()->format('m'), 2, '0', STR_PAD_LEFT);
        $monthNum = (int)$monthStr;

        Log::info('Finance Export starting', [
            'user' => auth()->id(),
            'tenant' => $tenantId,
            'year' => $year,
            'month' => $monthStr
        ]);

        // 2) Data Aggregation Logic
        $monthDate = Carbon::create($year, $monthNum, 1);
        $startDate = $monthDate->copy()->startOfMonth()->toDateString();
        $endDate = $monthDate->copy()->endOfMonth()->toDateString();
        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);

        // Get tenant trade name
        $tenantRecord = ($tenantId && $tenantId !== 'all') ? Tenant::find($tenantId) : null;
        $tenantName = $tenantRecord ? $tenantRecord->trade_name : 'All Tenants';
        $reportDateExpr = $this->localReportDateExpression('COALESCE(transaction_timestamp, created_at)');
        $joinedReportDateExpr = $this->localReportDateExpression('COALESCE(transactions.transaction_timestamp, transactions.created_at)');

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
        $otherTaxExpr = Schema::hasColumn('transactions', 'tax_exempt') ? 'SUM(tax_exempt)' : '0';

        // Optimized Main Aggregation
        $mainQuery = Transaction::query()
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
                {$otherTaxExpr} as transaction_other_tax,
                {$promoWithExpr} as promo_with_approval,
                {$promoWithoutExpr} as promo_without_approval
            ")
            ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantRecord) {
            $mainQuery->where('tenant_id', $tenantRecord->id);
        }
        if ($excludeVoids) {
            $mainQuery->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }
        $dailyMain = $mainQuery->groupBy('report_date')->get()->keyBy('report_date');

        // Optimized Adjustments. Some POS payloads persist senior/PWD discounts
        // in transaction_adjustments while transactions.senior_discount remains
        // lower or zero; include those fallback totals so generated CMSR matches
        // detailed transaction logs.
        $adjQuery = DB::table('transaction_adjustments')
            ->join('transactions', 'transaction_adjustments.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$joinedReportDateExpr} as report_date,
                SUM(IF(transaction_adjustments.adjustment_type IN ('employee_discount', 'EMPLOYEE'), transaction_adjustments.amount, 0)) as employee_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('senior_discount', 'senior_citizen_discount', 'senior'), transaction_adjustments.amount, 0)) as senior_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('pwd_discount', 'pwd_citizen_discount', 'pwddiscount', 'pwd'), transaction_adjustments.amount, 0)) as pwd_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('vip_card_discount', 'VIP'), transaction_adjustments.amount, 0)) as vip_discount
            ")
            ->whereRaw("{$joinedReportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantRecord) {
            $adjQuery->where('transactions.tenant_id', $tenantRecord->id);
        }
        if ($excludeVoids) {
            $adjQuery->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }
        $dailyAdj = $adjQuery->groupBy('report_date')->get()->keyBy('report_date');

        // Optimized Taxes (Fallback for SC Vat Exempt and Local Tax)
        $taxQuery = DB::table('transaction_taxes')
            ->join('transactions', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$joinedReportDateExpr} as report_date,
                SUM(IF(transaction_taxes.tax_type IN ('SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT'), transaction_taxes.amount, 0)) as sc_vat_exempt_fallback,
                SUM(IF(transaction_taxes.tax_type NOT IN ('VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT', 'VATEXEMPT_SALES', 'VAT_EXEMPT_SALES', 'ZERO_RATED', 'NON-VAT', 'NON_VAT', 'ZERO-RATED'), transaction_taxes.amount, 0)) as other_tax_basis
            ")
            ->whereRaw("{$joinedReportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($tenantRecord) {
            $taxQuery->where('transactions.tenant_id', $tenantRecord->id);
        }
        if ($excludeVoids) {
            $taxQuery->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }
        $dailyTax = $taxQuery->groupBy('report_date')->get()->keyBy('report_date');

        $service = app(\App\Services\Reports\FinanceCalculationService::class);
        $dailyPayloadAdjustments = $this->payloadAdjustmentTotals($startDate, $endDate, $tenantRecord?->id, $reportDateExpr, $service);
        $byDate = [];
        $allComponents = [];
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
                'senior_discount' => max((float)($tx->senior_discount ?? 0), (float)($adj->senior_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['senior_discount'] ?? 0)),
                'pwd_discount' => max((float)($tx->pwd_discount ?? 0), (float)($adj->pwd_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['pwd_discount'] ?? 0)),
                'vip_discount' => max((float)($adj->vip_discount ?? 0), (float)($dailyPayloadAdjustments[$date]['vip_discount'] ?? 0)),
                'other_tax' => max((float)($tx->transaction_other_tax ?? 0), (float)($tax->other_tax_basis ?? 0), (float)($dailyPayloadAdjustments[$date]['other_tax'] ?? 0)),
                'service_charge_distributed' => max((float)($tx->service_charge_distributed ?? 0), (float)($dailyPayloadAdjustments[$date]['service_charge_distributed'] ?? 0)),
                'service_charge_retained' => max((float)($tx->service_charge_retained ?? 0), (float)($dailyPayloadAdjustments[$date]['service_charge_retained'] ?? 0)),
                // CMSR does not expose a standalone "regular discount" column.
                // Excluding discount_total here keeps Gross Sales aligned with
                // visible CMSR columns and avoids discount double-counting.
                'regular_discount' => 0.0,
                'gross_sales' => (float)($tx->gross_sales ?? 0),
                'net_sales' => (float)($tx->net_sales ?? 0),
            ];

            if ($components['sc_vat_exempt_sales'] === 0.0 && isset($tax->sc_vat_exempt_fallback)) {
                $components['sc_vat_exempt_sales'] = (float)$tax->sc_vat_exempt_fallback;
            }

            foreach ($components as $key => $val) {
                $allComponents[$key] = ($allComponents[$key] ?? 0) + $val;
            }
            $derived = $service->deriveMetrics($components, ['gross_sales_basis' => 'pre_deduction']);
            $byDate[$date] = $derived;
        }

        $totals = $service->deriveMetrics($allComponents, ['gross_sales_basis' => 'pre_deduction']);

        // 3) Spreadsheet Generation
        $tpl = storage_path('app/templates/monthly_sales_template.xlsx');

        if (!class_exists(IOFactory::class)) {
            return response()->json(['error' => 'PhpSpreadsheet IOFactory not found'], 500);
        }
        if (!extension_loaded('zip')) {
            return response()->json(['error' => 'Server missing php-zip extension'], 500);
        }
        if (!file_exists($tpl)) {
            return response()->json(['error' => 'Excel template missing at: ' . $tpl], 500);
        }

        try {
            $spreadsheet = IOFactory::load($tpl);
            $sheet = $spreadsheet->getActiveSheet();

            // Headers
            $sheet->setCellValue("A9", $tenantName);
            $sheet->setCellValue("A11", "For the month of " . $monthDate->format('F Y'));

            // Logo
            $logo = public_path('images/mwm_logo.png');
            if (file_exists($logo)) {
                $draw = new Drawing();
                $draw->setPath($logo)->setCoordinates('M1')->setHeight(60)->setWorksheet($sheet);
            }

            // Daily Rows
            $startRow = 17;
            $daysInMonth = $monthDate->daysInMonth;
            for ($i = 0; $i < $daysInMonth; $i++) {
                $r = $startRow + $i;
                $day = $i + 1;
                $date = $monthDate->copy()->setDay($day)->format('Y-m-d');

                $sheet->setCellValue("A{$r}", $day);
                if (isset($byDate[$date])) {
                    $d = $byDate[$date];
                    $sheet->setCellValueExplicit("B{$r}", $d['vatable_sales'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("C{$r}", $d['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("D{$r}", $d['vat_amount'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("E{$r}", $d['promo_with_approval'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("F{$r}", $d['promo_without_approval'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("G{$r}", $d['employee_discount'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("H{$r}", $d['senior_discount'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("I{$r}", $d['pwd_discount'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("J{$r}", $d['vip_discount'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("K{$r}", $d['other_tax'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("L{$r}", $d['service_charge_distributed'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("M{$r}", $d['service_charge_retained'], DataType::TYPE_NUMERIC)
                        ->setCellValueExplicit("N{$r}", $d['gross_sales'], DataType::TYPE_NUMERIC);
                }
            }

            // Summary Totals
            $sheet->setCellValueExplicit("B49", (float)$totals['vatable_sales'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("C49", (float)$totals['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("D49", (float)$totals['vat_amount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("E49", (float)$totals['promo_with_approval'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("F49", (float)$totals['promo_without_approval'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("G49", (float)$totals['employee_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("H49", (float)$totals['senior_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("I49", (float)$totals['pwd_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("J49", (float)$totals['vip_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("K49", (float)$totals['other_tax'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("L49", (float)$totals['service_charge_distributed'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("M49", (float)$totals['service_charge_retained'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N49", (float)$totals['gross_sales'], DataType::TYPE_NUMERIC);

            // Summary Blocks
            $sheet->setCellValueExplicit("N51", (float)$totals['promo_with_approval'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N52", (float)$totals['promo_without_approval'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N53", (float)$totals['employee_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N54", (float)$totals['vip_discount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N55", (float)$totals['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N56", (float)$totals['senior_pwd'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N57", (float)$totals['other_tax'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N58", (float)$totals['service_charge_distributed'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N59", (float)$totals['service_charge_retained'], DataType::TYPE_NUMERIC);

            $sheet->setCellValueExplicit("N61", (float)$totals['net_sales'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N62", (float)$totals['vat_amount'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N64", (float)$totals['net_ex_vat'], DataType::TYPE_NUMERIC);

            $sheet->setCellValueExplicit("N66", (float)$totals['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N67", (float)$totals['promo_without_approval'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N68", (float)$totals['other_tax'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N69", (float)$totals['service_charge_retained'], DataType::TYPE_NUMERIC)
                ->setCellValueExplicit("N71", (float)$totals['net_subject_to_rent'], DataType::TYPE_NUMERIC);

            // Alignment / Basic Cleanup
            $sheet->getStyle("B17:N49")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->getColumnDimension('A')->setWidth(6);
            foreach (range('B', 'N') as $col) {
                $sheet->getColumnDimension($col)->setWidth(12);
            }

            // 4) Execute Download
            $filename = "SalesReport_{$year}_{$monthStr}.xlsx";
            
            // Audit Log
            try {
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'finance_export',
                ]);
            } catch (\Exception $e) {
                Log::warning('Audit failure: ' . $e->getMessage());
            }

            Log::info('Streaming export file: ' . $filename);

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0',
            ]);

        } catch (\Exception $e) {
            Log::error('Export failed: ' . $e->getMessage());
            return response()->json(['error' => 'Critical failure: ' . $e->getMessage()], 500);
        }
    }

    private function payloadAdjustmentTotals(string $startDate, string $endDate, $tenantId, string $reportDateExpr, \App\Services\Reports\FinanceCalculationService $service): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $rows = DB::table('transactions')
            ->selectRaw($reportDateExpr . ' as report_date')
            ->addSelect('original_payload')
            ->whereRaw($reportDateExpr . ' BETWEEN ? AND ?', [$startDate, $endDate])
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId));

        if (Schema::hasColumn('transactions', 'promo_status')) {
            $rows->addSelect('promo_status');
        }

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
                'other_tax' => 0.0,
            ];

            foreach ($service->adjustmentComponentsFromPayload($row->original_payload, $row->promo_status ?? null) as $key => $value) {
                $totals[$date][$key] += $value;
            }
        }

        return $totals;
    }

    private function localReportDateExpression(string $timestampExpression): string
    {
        $driver = DB::connection()->getDriverName();

        // CMSR must follow the POS business date stored on the transaction.
        // The live Bacolod June 21 case showed transaction_timestamp values are
        // already local business timestamps; applying +08:00 shifted evening
        // sales into June 22 and understated June 21 SC discounts.
        if ($driver === 'sqlite') {
            return "DATE({$timestampExpression})";
        }

        if ($driver === 'pgsql') {
            return "DATE({$timestampExpression})";
        }

        return "DATE({$timestampExpression})";
    }

    private function reportTimezone(): string
    {
        return config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila';
    }
}
