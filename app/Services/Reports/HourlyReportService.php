<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Service that encapsulates the hourly aggregation logic used by both
 * the API and the web (commercial) controllers.
 */
class HourlyReportService
{
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
                $reportDateExpr = $this->reportDateExpression('COALESCE(transaction_timestamp, completed_at, created_at)', 'original_payload');

                $query = Transaction::query()
                    ->with(['adjustments', 'taxes'])
                    ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$dateFrom, $dateTo]);
    
                if (! empty($tenantId)) {
                    $query->where('tenant_id', $tenantId);
                }
                if (! empty($terminalId)) {
                    $query->where('terminal_id', $terminalId);
                }
                if (config('tsms.reporting.exclude_voids_from_totals', true)) {
                    $query->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
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
        $timestamp = $transaction->transaction_timestamp
            ?? $transaction->completed_at
            ?? $transaction->created_at;

        $carbon = Carbon::parse($timestamp);
        if (! $this->payloadTimestampIsPlainLocal($transaction->original_payload ?? null)) {
            $carbon = $carbon->copy()->timezone($this->reportTimezone());
        }

        return $carbon->format('Y-m-d H:00');
    }

    private function payloadTimestampIsPlainLocal(mixed $payload): bool
    {
        if (! is_string($payload) || $payload === '') {
            return false;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return false;
        }

        $timestamp = $decoded['transaction_timestamp'] ?? $decoded['transaction']['transaction_timestamp'] ?? null;

        return is_string($timestamp)
            && $timestamp !== ''
            && ! preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/', $timestamp);
    }

    private function reportDateExpression(string $timestampExpression, string $payloadExpression): string
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return $this->localReportDateExpression($timestampExpression);
        }

        $driver = DB::connection()->getDriverName();
        $localDateExpression = $this->localReportDateExpression($timestampExpression);

        if ($driver === 'pgsql') {
            $payloadTimestamp = "COALESCE(({$payloadExpression})::jsonb->>'transaction_timestamp', ({$payloadExpression})::jsonb#>>'{transaction,transaction_timestamp}')";

            return "CASE
                WHEN {$payloadExpression} IS NOT NULL
                    AND {$payloadExpression} != ''
                    AND {$payloadTimestamp} IS NOT NULL
                    AND {$payloadTimestamp} !~ '(Z|[+-][0-9]{2}:?[0-9]{2})$'
                THEN DATE({$timestampExpression})
                ELSE {$localDateExpression}
            END";
        }

        $payloadTimestamp = "CASE
            WHEN {$payloadExpression} IS NOT NULL AND {$payloadExpression} != '' AND JSON_VALID({$payloadExpression})
            THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$payloadExpression}, '$.transaction_timestamp')), JSON_UNQUOTE(JSON_EXTRACT({$payloadExpression}, '$.transaction.transaction_timestamp')))
            ELSE NULL
        END";

        if ($driver === 'sqlite') {
            $payloadTimestamp = "COALESCE(json_extract({$payloadExpression}, '$.transaction_timestamp'), json_extract({$payloadExpression}, '$.transaction.transaction_timestamp'))";

            return "CASE
                WHEN {$payloadExpression} IS NOT NULL
                    AND {$payloadExpression} != ''
                    AND {$payloadTimestamp} IS NOT NULL
                    AND {$payloadTimestamp} NOT LIKE '%Z'
                    AND {$payloadTimestamp} NOT GLOB '*[+-][0-9][0-9]:[0-9][0-9]'
                    AND {$payloadTimestamp} NOT GLOB '*[+-][0-9][0-9][0-9][0-9]'
                THEN DATE({$timestampExpression})
                ELSE {$localDateExpression}
            END";
        }

        return "CASE
            WHEN {$payloadExpression} IS NOT NULL
                AND {$payloadExpression} != ''
                AND {$payloadTimestamp} IS NOT NULL
                AND {$payloadTimestamp} NOT REGEXP '(Z|[+-][0-9]{2}:?[0-9]{2})$'
            THEN DATE({$timestampExpression})
            ELSE {$localDateExpression}
        END";
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
}
