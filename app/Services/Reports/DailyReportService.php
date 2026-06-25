<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Facades\Schema;

class DailyReportService
{
    private static ?bool $hasTransactionDate = null;

    private function reportDateExpression(): string
    {
        self::$hasTransactionDate ??= Schema::hasColumn('transactions', 'transaction_date');

        return self::$hasTransactionDate
            ? 'transaction_date'
            : 'DATE(transaction_timestamp)';
    }

    /**
     * Return a summary and optional hourly breakdown for a single date.
     *
     * @param  string       $date         Y-m-d
     * @param  int|null     $tenantId     null → all tenants
     * @param  int|null     $terminalId   null → all terminals
     * @param  bool         $includeHourly  include 'hours' key in result
     * @return array{summary: array, hours?: array}
     */
    public function getDailySummary(
        string $date,
        $tenantId = null,
        $terminalId = null,
        bool $includeHourly = false
    ): array {
        $dateExpr = $this->reportDateExpression();

        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);

        $base = Transaction::query()
            ->when(
                self::$hasTransactionDate,
                fn ($query) => $query->where('transaction_date', $date),
                fn ($query) => $query->whereRaw("{$dateExpr} = ?", [$date])
            );

        if ($tenantId && $tenantId !== 'all') {
            $base->where('tenant_id', $tenantId);
        }
        if ($terminalId) {
            $base->where('terminal_id', $terminalId);
        }
        if ($excludeVoids) {
            $base->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }

        $row = (clone $base)
            ->selectRaw("
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
            ->first();

        $summary = [
            'gross_sales'        => round((float) ($row->gross_sales ?? 0), 2),
            'net_sales'          => round((float) ($row->net_sales ?? 0), 2),
            'vatable_sales'      => round((float) ($row->vatable_sales ?? 0), 2),
            'vat_amount'         => round((float) ($row->vat_amount ?? 0), 2),
            'vat_exempt_sales'   => round((float) ($row->sc_vat_exempt_sales ?? 0), 2),
            'sc_pwd_discount'    => round((float) (($row->senior_discount ?? 0) + ($row->pwd_discount ?? 0)), 2),
            'regular_discount'   => round((float) ($row->discount_total ?? 0), 2),
            'transaction_count'  => (int) ($row->transaction_count ?? 0),
            'guest_count'        => 0,
            'cash_payment'       => 0.0,
            'card_payment'       => 0.0,
            'other_tender'       => 0.0,
        ];

        $result = ['summary' => $summary];

        if ($includeHourly) {
            $hours = (clone $base)
                ->selectRaw("
                    HOUR(transaction_timestamp) as hour,
                    SUM(gross_sales)            as gross_sales,
                    SUM(net_sales)              as net_sales,
                    COUNT(*)                    as transaction_count
                ")
                ->groupByRaw('HOUR(transaction_timestamp)')
                ->orderByRaw('HOUR(transaction_timestamp)')
                ->get()
                ->map(fn ($h) => [
                    'hour'              => sprintf('%02d:00', $h->hour),
                    'gross_sales'       => round((float) $h->gross_sales, 2),
                    'net_sales'         => round((float) $h->net_sales, 2),
                    'transaction_count' => (int) $h->transaction_count,
                ])
                ->values()
                ->toArray();

            $result['hours'] = $hours;
        }

        return $result;
    }
}
