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
        $year  = (int) $request->query('year', now()->year);
        $month = str_pad($request->query('month', now()->format('m')), 2, '0', STR_PAD_LEFT);
        $tenant = $request->query('tenant', null);

        // 2) Build query on created_at (to match PDF logic), optional store filter
        $query = Transaction::query();
        $tenantName = 'All Tenants';
        
        if ($tenant && $tenant !== 'all') {
            $query->where('tenant_id', $tenant);
            // Get the tenant name for display
            $tenantRecord = \App\Models\Tenant::find($tenant);
            $tenantName = $tenantRecord ? $tenantRecord->trade_name : 'Unknown Tenant';
        }
        
        $query->whereYear('created_at', $year)
              ->whereMonth('created_at', $month)
              ->orderBy('created_at');
        $transactions = $query->get();

        // 3) Group by date and compute daily aggregates
        $byDate = $transactions
            ->groupBy(fn($tx) => $tx->created_at->format('Y-m-d'))
            ->map(function($group) {
                return [
                    'net_sales'     => $group->sum('net_sales'),
                    'vatable'       => $group->sum('vatable_sales'),
                    'exempt'        => $group->sum('vat_exempt_sales'),
                    'vat'           => $group->sum('vat_amount'),
                    'promo_with'    => $group->sum('promo_with_approval'),
                    'promo_without' => $group->sum('promo_without_approval'),
                    'emp_disc'      => $group->sum('employee_discount'),
                    'senior_disc'   => $group->sum('senior_discount'),
                    'pwd_disc'      => $group->sum('pwd_discount'),
                    'vip_disc'      => $group->sum('vip_discount'),
                    'other_tax'     => $group->sum('other_tax'),
                    'sc_dist'       => $group->sum('service_charge_distributed'),
                    'sc_ret'        => $group->sum('service_charge_retained'),
                    'gross'         => $group->sum('gross_sales'),
                ];
            })
            ->toArray();

        // 4) Compute full-month totals
        $totals = array_reduce($byDate, function($carry, $day) {
            foreach ($day as $k => $v) {
                $carry[$k] = ($carry[$k] ?? 0) + $v;
            }
            return $carry;
        }, [
            'net_sales'=>0,'vatable'=>0,'exempt'=>0,'vat'=>0,
            'promo_with'=>0,'promo_without'=>0,
            'emp_disc'=>0,'senior_disc'=>0,'pwd_disc'=>0,'vip_disc'=>0,
            'other_tax'=>0,'sc_dist'=>0,'sc_ret'=>0,'gross'=>0,
        ]);

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
        $startRow    = 17;
        $firstOfMonth = Carbon::create($year, $month, 1);
        $daysInMonth = $firstOfMonth->daysInMonth;

        for ($i = 0; $i < $daysInMonth; $i++) {
            $r    = $startRow + $i;
            $day  = $i + 1;
            $date = $firstOfMonth->copy()->addDays($i)->format('Y-m-d');

            $sheet->setCellValue("A{$r}", $day);

            if (isset($byDate[$date])) {
                $d = $byDate[$date];
                $totaldiscount = $d['promo_with']
                             + $d['promo_without']
                             + $d['emp_disc']
                             + $d['senior_disc']
                             + $d['pwd_disc']
                             + $d['vip_disc']
                             + $d['sc_dist']
                             + $d['sc_ret']
                             + $d['other_tax'];
                
                $totalVat = $d['exempt'] + $d['vat'];
                             
                             
                $vatable = $d['gross'] - ($totalVat + $totaldiscount);
                         
                $sheet
                    ->setCellValueExplicit("B{$r}", $vatable,      DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("C{$r}", $d['exempt'],       DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("D{$r}", $d['vat'],          DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("E{$r}", $d['promo_with'],    DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("F{$r}", $d['promo_without'], DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("G{$r}", $d['emp_disc'],      DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("H{$r}", $d['senior_disc'],   DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("I{$r}", $d['pwd_disc'],      DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("J{$r}", $d['vip_disc'],      DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("K{$r}", $d['other_tax'],     DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("L{$r}", $d['sc_dist'],       DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("M{$r}", $d['sc_ret'],        DataType::TYPE_NUMERIC)
                    ->setCellValueExplicit("N{$r}", $d['gross'],         DataType::TYPE_NUMERIC);
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
            ->setCellValueExplicit("B{$totalRow}", ($totals['gross'] - ($totals['exempt'] + $totals['vat'] + $totals['promo_with'] + $totals['promo_without'] + $totals['emp_disc'] + $totals['senior_disc'] + $totals['pwd_disc'] + $totals['vip_disc'] + $totals['sc_dist'] + $totals['sc_ret'] + $totals['other_tax'])), DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("C{$totalRow}", $totals['exempt'],       DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("D{$totalRow}", $totals['vat'],          DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("E{$totalRow}", $totals['promo_with'],    DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("F{$totalRow}", $totals['promo_without'], DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("G{$totalRow}", $totals['emp_disc'],      DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("H{$totalRow}", $totals['senior_disc'],   DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("I{$totalRow}", $totals['pwd_disc'],      DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("J{$totalRow}", $totals['vip_disc'],      DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("K{$totalRow}", $totals['other_tax'],     DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("L{$totalRow}", $totals['sc_dist'],       DataType::TYPE_NUMERIC)
            ->setCellValueExplicit("M{$totalRow}", $totals['sc_ret'],         DataType::TYPE_NUMERIC);

        // 8) "Less:" summary at rows 51–59
        $sheet->setCellValueExplicit("N51", $totals['promo_with'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N52", $totals['promo_without'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N53", $totals['emp_disc'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N54", $totals['vip_disc'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N55", $totals['exempt'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N56", $totals['senior_disc'] + $totals['pwd_disc'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N57", $totals['other_tax'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N58", $totals['sc_dist'], DataType::TYPE_NUMERIC)
              ->setCellValueExplicit("N59", $totals['sc_ret'], DataType::TYPE_NUMERIC);

        // 9) Net Sales, VAT, Net ex-VAT (61, 62, 64)
        $netSales   = $totals['gross'] - ($totals['exempt'] 
                                        + $totals['vat'] + $totals['promo_with'] 
                                        + $totals['promo_without'] + $totals['emp_disc'] 
                                        + $totals['senior_disc'] 
                                        + $totals['pwd_disc'] 
                                        + $totals['vip_disc'] 
                                        + $totals['sc_dist'] 
                                        + $totals['sc_ret'] 
                                        + $totals['other_tax']) + $totals['vat'];
        $vatAmount  = ($netSales / 1.12)*0.12;
        $netExclVAT = $netSales / 1.12;

        $sheet->setCellValue("A61", 'Net Sales')
              ->setCellValueExplicit("N61", $netSales,   DataType::TYPE_NUMERIC);
        $sheet->setCellValue("A62", 'Less 12% VAT')
              ->setCellValueExplicit("N62", $vatAmount,  DataType::TYPE_NUMERIC);
        $sheet->setCellValue("A64", '')
              ->setCellValueExplicit("N64", $netExclVAT,DataType::TYPE_NUMERIC);

        // 10) "Add:" block at rows 66–69
        $adds = [
           'SC Vat Exempt Transactions'            => $totals['exempt'],
           'Promo Discounts Without Approval'      => $totals['promo_without'],
           'Other Tax'                             => $totals['other_tax'],
           'Service Charge Retained by Management' => $totals['sc_ret'],
        ];
        $row = 66;
        foreach ($adds as $label => $val) {
            $sheet->setCellValueExplicit("N{$row}", $val, DataType::TYPE_NUMERIC);
            $row++;
        }

        // 11) Final "Net Sales Subject to Percentage rent" at row 71
        $final = $netExclVAT
               + ($totals['exempt'] ?? 0)
               + ($totals['promo_without'] ?? 0)
               + ($totals['other_tax'] ?? 0)
               + ($totals['sc_ret'] ?? 0);

        $sheet->setCellValueExplicit("N71", $final, DataType::TYPE_NUMERIC);

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
        return response()->streamDownload(function() use($spreadsheet) {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $filename, [
            'Content-Type' => 
              'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ]);
    }
}
