<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Illuminate\Support\Facades\Log;
use App\Models\AuditLog;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SalesReportExportController extends Controller
{
    public function export(Request $request)
    {
        // 1) Read & sanitize inputs
        $year = (int) $request->query('year', now()->year);
        $month = str_pad($request->query('month', now()->format('m')), 2, '0', STR_PAD_LEFT);
        $tenant = $request->query('tenant', null);

        // 2) Build query using canonical transaction timestamp when present
        //    Fallback to created_at/completed_at so export matches dashboard/reporting logic
        $query = Transaction::query();
        $tenantName = 'All Tenants';

        if ($tenant && $tenant !== 'all') {
            $query->where('tenant_id', $tenant);
            // Get the tenant name for display
            $tenantRecord = \App\Models\Tenant::find($tenant);
            $tenantName = $tenantRecord ? $tenantRecord->trade_name : 'Unknown Tenant';
        }

        // Use COALESCE(transaction_timestamp, completed_at, created_at) as the canonical
        // transaction time for reporting consistency with dashboard.
        $query->whereRaw("YEAR(COALESCE(transaction_timestamp, completed_at, created_at)) = ?", [$year])
            ->whereRaw("MONTH(COALESCE(transaction_timestamp, completed_at, created_at)) = ?", [$month])
            ->orderByRaw("COALESCE(transaction_timestamp, completed_at, created_at)");
        $transactions = $query->get();

        $service = app(\App\Services\Reports\FinanceCalculationService::class);

        // 3) Group by date and compute daily aggregates using the shared service
        $byDate = $transactions
            ->groupBy(function ($tx) {
                $ts = $tx->transaction_timestamp ?? $tx->completed_at ?? $tx->created_at;
                return
                    $ts instanceof \Carbon\Carbon
                    ? $ts->format('Y-m-d')
                    : \Carbon\Carbon::parse($ts)->format('Y-m-d');
            })
            ->map(function ($group) use ($service) {
                $components = $service->aggregateComponents($group);
                return $service->deriveMetrics($components);
            })
            ->toArray();

        // 4) Compute full-month totals using the shared service
        $totals = $service->deriveMetrics($service->aggregateComponents($transactions));


        // 5) Load template & (optional) embed logo
        $tpl = storage_path('app/templates/monthly_sales_template.xlsx');

        // Guard: ensure PhpSpreadsheet is available
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            Log::error('PhpSpreadsheet IOFactory class not found. Please install phpoffice/phpspreadsheet via composer.');
            return response()->json([
                'status' => 'error',
                'message' => 'Export failed: server is missing the phpoffice/phpspreadsheet package. Run "composer require phpoffice/phpspreadsheet" on the server.'
            ], 500);
        }

        if (!file_exists($tpl)) {
            Log::error("Spreadsheet template not found: {$tpl}");
            return response()->json([
                'status' => 'error',
                'message' => "Export failed: template not found ({$tpl})."
            ], 500);
        }

        try {
            $spreadsheet = IOFactory::load($tpl);
        } catch (\Throwable $e) {
            Log::error('Failed to load spreadsheet template: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Export failed: unable to load spreadsheet template.'
            ], 500);
        }

        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue("A9", $tenantName);
        $sheet->setCellValue("A11", "For the month of " . Carbon::createFromDate($year, $month, 1)->format('F Y'));
        $logo = public_path('images/mwm_logo.png');
        if (file_exists($logo)) {
            $draw = new Drawing();
            $draw->setPath($logo)
                ->setCoordinates('M1')
                ->setHeight(60)
                ->setWorksheet($sheet);
        }

        // 6) Fill daily rows (1..daysInMonth) at row 17+
        $startRow = 17;
        $firstOfMonth = Carbon::create($year, $month, 1);
        $daysInMonth = $firstOfMonth->daysInMonth;

        for ($i = 0; $i < $daysInMonth; $i++) {
            $r = $startRow + $i;
            $day = $i + 1;
            $date = $firstOfMonth->copy()->addDays($i)->format('Y-m-d');

            $sheet->setCellValue("A{$r}", $day);

            if (isset($byDate[$date])) {
                $d = $byDate[$date];

                // Compute per-day values using deriveMetrics output from FinanceCalculationService
                // (Already aggregated in $byDate)

                // Calculate Gross Sales first (without VAT, will recalculate after getting correct VAT)
                $sheet
                    ->setCellValueExplicit("B{$r}", $d['vatable_sales'], DataType::TYPE_NUMERIC)
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

        // Apply numeric formatting and alignment for daily rows so zeros and amounts align on print
        $dayRangeStart = $startRow;
        $dayRangeEnd = $startRow + $daysInMonth - 1;
        $numberRange = "B{$dayRangeStart}:N{$dayRangeEnd}";
        // Two decimals, thousand separator where applicable
        $sheet->getStyle($numberRange)
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle($numberRange)
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        // Make date/index column centered
        $sheet->getStyle("A{$dayRangeStart}:A{$dayRangeEnd}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        // Set column widths for better print alignment
        foreach (range('B', 'N') as $col) {
            $sheet->getColumnDimension($col)->setWidth(12);
        }
        $sheet->getColumnDimension('A')->setWidth(6);

        // 7) “Total” row at 49
        $totalRow = 49;
        $sheet->setCellValue("A{$totalRow}", 'Total')
            // B: Vatable Sales (VAT-exclusive)
            ->setCellValueExplicit("B{$totalRow}", round($totals['vatable_sales'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("C{$totalRow}", round($totals['sc_vat_exempt_sales'] ?? 0, 2), DataType::TYPE_NUMERIC)
            // D: VAT computed as Vatable * 12%
            ->setCellValueExplicit("D{$totalRow}", round($totals['vat_amount'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("E{$totalRow}", round($totals['promo_with_approval'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("F{$totalRow}", round($totals['promo_without_approval'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("G{$totalRow}", round($totals['employee_discount'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("H{$totalRow}", round($totals['senior_discount'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("I{$totalRow}", round($totals['pwd_discount'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("J{$totalRow}", round($totals['vip_discount'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("K{$totalRow}", round($totals['other_tax'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("L{$totalRow}", round($totals['service_charge_distributed'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("M{$totalRow}", round($totals['service_charge_retained'] ?? 0, 2), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N{$totalRow}", round($totals['gross_sales'] ?? 0, 2), DataType::TYPE_NUMERIC);

        // 8) "Less:" summary at rows 51–59
        $sheet->setCellValueExplicit("N51", $totals['promo_with_approval'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N52", $totals['promo_without_approval'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N53", $totals['employee_discount'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N54", $totals['vip_discount'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N55", $totals['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N56", $totals['senior_pwd'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N57", $totals['other_tax'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N58", $totals['service_charge_distributed'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N59", $totals['service_charge_retained'], DataType::TYPE_NUMERIC);

        // 9) Net Sales, VAT, Net ex-VAT (61, 62, 64)
        $sheet->setCellValue("A61", 'Net Sales')
            ->setCellValueExplicit("N61", $totals['net_sales'], DataType::TYPE_NUMERIC);
        $sheet->setCellValue("A62", 'Less 12% VAT')
            ->setCellValueExplicit("N62", $totals['vat_amount'], DataType::TYPE_NUMERIC);
        $sheet->setCellValue("A64", '')
            ->setCellValueExplicit("N64", $totals['net_ex_vat'], DataType::TYPE_NUMERIC);

        // 10) "Add:" block at rows 66–69
        $sheet->setCellValueExplicit("N66", $totals['sc_vat_exempt_sales'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N67", $totals['promo_without_approval'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N68", $totals['other_tax'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("N69", $totals['service_charge_retained'], DataType::TYPE_NUMERIC);

        // 11) Final "Net Sales Subject to Percentage rent" at row 71
        $sheet->setCellValueExplicit("N71", $totals['net_subject_to_rent'], DataType::TYPE_NUMERIC);

        // 12) Stream download — prepare filename first then record activity
        $filename = "SalesReport_{$year}-{$month}.xlsx";

        // 13) Record export activity using AuditLog (Activity facade not present in this branch)
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.sales_monthly_export',
                'action_type' => 'export',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Exported sales report {$filename}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['year' => $year, 'month' => $month, 'tenant' => $tenant],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never let audit failures block the export; log for ops
            Log::warning('Failed to write AuditLog for sales export: ' . $e->getMessage(), ['year' => $year, 'month' => $month, 'tenant' => $tenant]);
        }

        Log::info('Sales report exported', ['filename' => $filename, 'user_id' => auth()->id(), 'tenant' => $tenant ?? 'all']);

        // 14) Stream download
        return response()->streamDownload(function () use ($spreadsheet) {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $filename, [
            'Content-Type' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ]);
    }
}
