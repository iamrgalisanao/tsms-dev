<?php

namespace App\Services\Reports;

use Carbon\Carbon;

class WeeklyReportService
{
    public function __construct(private readonly ?SalesReportDataService $salesReportData = null)
    {
    }

    /**
     * Aggregate daily rows between $from and $to.
     *
     * @param  string    $from          Y-m-d
     * @param  string    $to            Y-m-d
     * @param  int|null  $tenantId      null → all tenants
     * @param  bool      $weekdayOnly   keep only Mon–Fri rows
     * @param  bool      $weekendOnly   keep only Sat–Sun rows
     * @param  bool      $flatDays      (unused, kept for signature compatibility)
     * @return array{summary: array, days: array}
     */
    public function getWeeklySummary(
        string $from,
        string $to,
        $tenantId = null,
        bool $weekdayOnly = false,
        bool $weekendOnly = false,
        bool $flatDays = false
    ): array {
        $result = ($this->salesReportData ?? app(SalesReportDataService::class))
            ->getCmsrReportData(SalesReportFilter::forTenantDateRange($tenantId, $from, $to));

        $days = [];
        $summary = [
            'gross_sales'       => 0.0,
            'net_sales'         => 0.0,
            'vatable_sales'     => 0.0,
            'vat_amount'        => 0.0,
            'vat_exempt_sales'  => 0.0,
            'sc_pwd_discount'   => 0.0,
            'regular_discount'  => 0.0,
            'cash_payment'      => 0.0,
            'card_payment'      => 0.0,
            'other_tender'      => 0.0,
            'transaction_count' => 0,
            'guest_count'       => 0,
        ];

        $start = Carbon::parse($from);
        $end   = Carbon::parse($to);

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($weekdayOnly && $d->isWeekend()) {
                continue;
            }
            if ($weekendOnly && ! $d->isWeekend()) {
                continue;
            }

            $date = $d->format('Y-m-d');
            $metrics = $result->dailyTotals[$date] ?? [];
            $day = [
                'date'              => $date,
                'gross_sales'       => round((float) ($metrics['gross_sales'] ?? 0), 2),
                'net_sales'         => round((float) ($metrics['net_total'] ?? $metrics['net_sales'] ?? 0), 2),
                'vatable_sales'     => round((float) ($metrics['vatable_sales'] ?? 0), 2),
                'vat_amount'        => round((float) ($metrics['vat_amount'] ?? 0), 2),
                'vat_exempt_sales'  => round((float) ($metrics['sc_vat_exempt_sales'] ?? 0), 2),
                'sc_pwd_discount'   => round((float) ($metrics['senior_pwd'] ?? 0), 2),
                'regular_discount'  => round((float) (($metrics['regular_discount'] ?? 0) + ($metrics['total_promotions'] ?? 0)), 2),
                'cash_payment'      => 0.0,
                'card_payment'      => 0.0,
                'other_tender'      => 0.0,
                'transaction_count' => (int) ($metrics['transaction_count'] ?? 0),
                'guest_count'       => 0,
            ];
            $days[] = $day;

            $summary['gross_sales']       += $day['gross_sales'];
            $summary['net_sales']         += $day['net_sales'];
            $summary['vatable_sales']     += $day['vatable_sales'];
            $summary['vat_amount']        += $day['vat_amount'];
            $summary['vat_exempt_sales']  += $day['vat_exempt_sales'];
            $summary['sc_pwd_discount']   += $day['sc_pwd_discount'];
            $summary['regular_discount']  += $day['regular_discount'];
            $summary['transaction_count'] += $day['transaction_count'];
        }

        foreach (['gross_sales', 'net_sales', 'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'sc_pwd_discount', 'regular_discount'] as $k) {
            $summary[$k] = round($summary[$k], 2);
        }

        return ['summary' => $summary, 'days' => $days];
    }
}
