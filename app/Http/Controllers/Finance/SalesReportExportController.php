<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class SalesReportExportController extends Controller
{
    /**
     * Export the Certified Monthly Sales Report (CMSR) as an Excel file.
     * Uses native PHP headers for maximum compatibility with all browsers.
     */
    public function export(Request $request, SalesReportDataService $salesReportData)
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

        // 2) Use the same CMSR data source as the web report.
        $filter = SalesReportFilter::forTenantYearMonth($tenantId, $year, $monthNum);
        $monthDate = $filter->monthDate;
        $tenantRecord = ($tenantId && $tenantId !== 'all') ? Tenant::find($tenantId) : null;
        $tenantName = $tenantRecord ? $tenantRecord->trade_name : 'All Tenants';
        $report = $salesReportData->getCmsrReportData($filter);
        $byDate = $report->dailyTotals;
        $totals = $report->totals;

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

}
