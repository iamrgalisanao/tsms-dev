<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use App\Traits\ResolvesReportBusinessDate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * Service that encapsulates the hourly aggregation logic used by both
 * the API and the web (commercial) controllers.
 */
class HourlyReportService
{
    use ResolvesReportBusinessDate;

    /**
     * Fetch hourly aggregates for the given date range and optional tenant/terminal filters.
     * Returns a collection (array) of normalized rows with the same contract
     * as Api\Webapp\HourlyTransactionsController produced previously.
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     * @param string|null $tenantId
     * @param string|null $terminalId
     * @return array
     */
    public function getHourlyAggregates(string $dateFrom, string $dateTo, ?string $tenantId = null, ?string $terminalId = null, bool $scaleToMillions = false): array
    {
        try {
            $cacheKey = sprintf('reports:hourly:%s:%s:tenant:%s:terminal:%s:scale:%s', $dateFrom, $dateTo, $tenantId ?? 'all', $terminalId ?? 'all', $scaleToMillions ? '1' : '0');
            $ttl = 60;

            return Cache::remember($cacheKey, $ttl, function () use ($dateFrom, $dateTo, $tenantId, $terminalId) {
                $reportDateExpr = $this->reportDateExpression(
                    'COALESCE(transactions.transaction_timestamp, transactions.completed_at, transactions.created_at)',
                    'transactions.original_payload',
                    'pp.timestamp_mode',
                    'transactions.transaction_timestamp'
                );

                $query = Transaction::query()
                    ->select('transactions.*')
                    ->with(['adjustments', 'taxes', 'terminal.provider'])
                    ->leftJoin('pos_terminals as pt', 'pt.id', '=', 'transactions.terminal_id')
                    ->leftJoin('pos_providers as pp', 'pp.id', '=', 'pt.provider_id')
                    ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);

                if (! empty($tenantId)) {
                    $query->where('transactions.tenant_id', $tenantId);
                }
                if (! empty($terminalId)) {
                    $query->where('transactions.terminal_id', $terminalId);
                }
                if (config('tsms.reporting.exclude_voids_from_totals', true)) {
                    $query->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
                }

                $finance = app(FinanceCalculationService::class);
                $groups = $query->get()->groupBy(fn ($tx) => $this->reportHour($tx));

                return $groups->sortKeys()->map(function (Collection $transactions, string $hour) use ($finance) {
                    $metrics = $finance->deriveMetrics(
                        $finance->aggregateComponents($transactions),
                        ['gross_sales_basis' => 'pre_deduction']
                    );

                    return [
                        'customer_code' => null,
                        'tenant_name' => null,
                        'location' => null,
                        'zone' => null,
                        'sales_date' => substr($hour, 0, 10),
                        'hour' => substr($hour, 11, 5),
                        'gross_sales' => round((float) ($metrics['gross_sales'] ?? 0), 2),
                        'vatable_sales' => round((float) ($metrics['vatable_sales'] ?? 0), 2),
                        'vat_exempt_sales' => round((float) ($metrics['sc_vat_exempt_sales'] ?? 0), 2),
                        'vat_amount' => round((float) ($metrics['vat_amount'] ?? 0), 2),
                        'sc_pwd_discount' => round((float) ($metrics['senior_pwd'] ?? 0), 2),
                        'regular_discount' => round((float) (($metrics['regular_discount'] ?? 0) + ($metrics['total_promotions'] ?? 0)), 2),
                        'void' => 0.0,
                        'return' => 0.0,
                        'net_sales' => round((float) ($metrics['net_total'] ?? $metrics['net_sales'] ?? 0), 2),
                        'cash_payment' => 0.0,
                        'card_payment' => 0.0,
                        'other_tender' => 0.0,
                        'net_sales_percentage_rent' => round((float) ($metrics['net_subject_to_rent'] ?? 0), 2),
                        'transaction_count' => $transactions->count(),
                        'guest_count' => 0,
                        'gross_sales_m' => round(((float) ($metrics['gross_sales'] ?? 0)) / 1000000.0, 4),
                        'net_sales_m' => round(((float) ($metrics['net_total'] ?? $metrics['net_sales'] ?? 0)) / 1000000.0, 4),
                    ];
                })->values()->toArray();
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('HourlyReportService live aggregation failed: ' . $e->getMessage(), ['date_from' => $dateFrom, 'date_to' => $dateTo]);
            return [];
        }
    }

    private function reportHour(Transaction $transaction): string
    {
        return $this->resolveBusinessMoment($transaction)->format('Y-m-d H:00');
    }
}
