<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class WeeklyReportService
{
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
        $dateExpr = Schema::hasColumn('transactions', 'transaction_date')
            ? 'transaction_date'
            : 'DATE(transaction_timestamp)';

        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);

        $query = Transaction::query()
            ->selectRaw("
                {$dateExpr}               as report_date,
                SUM(gross_sales)          as gross_sales,
                SUM(net_sales)            as net_sales,
                SUM(vatable_sales)        as vatable_sales,
                SUM(vat_amount)           as vat_amount,
                SUM(sc_vat_exempt_sales)  as sc_vat_exempt_sales,
                SUM(discount_total)       as discount_total,
                SUM(senior_discount)      as senior_discount,
                SUM(pwd_discount)         as pwd_discount,
                COUNT(*)                  as transaction_count
            ")
            ->whereRaw("{$dateExpr} BETWEEN ? AND ?", [$from, $to]);

        if ($tenantId && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }
        if ($excludeVoids) {
            $query->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }
        if ($weekdayOnly) {
            // MySQL DAYOFWEEK: 1=Sun, 2=Mon … 7=Sat — weekdays are 2–6
            $query->whereRaw("DAYOFWEEK({$dateExpr}) BETWEEN 2 AND 6");
        }
        if ($weekendOnly) {
            $query->whereRaw("DAYOFWEEK({$dateExpr}) IN (1, 7)");
        }

        $rows = $query
            ->groupBy('report_date')
            ->orderBy('report_date')
            ->get()
            ->keyBy('report_date');

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
            $r    = $rows->get($date);

            $scPwd = round((float) (($r->senior_discount ?? 0) + ($r->pwd_discount ?? 0)), 2);
            $day   = [
                'date'              => $date,
                'gross_sales'       => round((float) ($r->gross_sales ?? 0), 2),
                'net_sales'         => round((float) ($r->net_sales ?? 0), 2),
                'vatable_sales'     => round((float) ($r->vatable_sales ?? 0), 2),
                'vat_amount'        => round((float) ($r->vat_amount ?? 0), 2),
                'vat_exempt_sales'  => round((float) ($r->sc_vat_exempt_sales ?? 0), 2),
                'sc_pwd_discount'   => $scPwd,
                'regular_discount'  => round((float) ($r->discount_total ?? 0), 2),
                'cash_payment'      => 0.0,
                'card_payment'      => 0.0,
                'other_tender'      => 0.0,
                'transaction_count' => (int) ($r->transaction_count ?? 0),
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
