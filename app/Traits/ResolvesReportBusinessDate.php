<?php

namespace App\Traits;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ResolvesReportBusinessDate
{
    /**
     * @param string|null $providerTimestampModeExpression SQL expression resolving to the
     *   terminal's provider.timestamp_mode (e.g. 'pp.timestamp_mode'), or null to skip the
     *   legacy no-payload fallback entirely. Only consulted when original_payload is NULL —
     *   it must never override a payload-confirmed classification.
     * @param string|null $primaryTimestampColumn Raw column reference for transaction_timestamp
     *   itself (e.g. 'transactions.transaction_timestamp'), as opposed to the (possibly
     *   COALESCE-wrapped) $timestampExpression. Required alongside
     *   $providerTimestampModeExpression: the legacy fallback only applies when
     *   transaction_timestamp itself supplied the value, not when $timestampExpression fell
     *   through to a completed_at/created_at fallback (those columns have different, unproven
     *   timezone semantics).
     */
    protected function reportDateExpression(
        string $timestampExpression,
        string $payloadExpression,
        ?string $providerTimestampModeExpression = null,
        ?string $primaryTimestampColumn = null
    ): string {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return $this->localReportDateExpression($timestampExpression);
        }

        $driver = DB::connection()->getDriverName();
        $localDateExpression = $this->localReportDateExpression($timestampExpression);

        // Legacy rows (no payload ever captured): the pre-normalization ingestion pipeline
        // stored transaction_timestamp verbatim for every provider, so a local_time_with_z
        // provider's rows are still raw, un-shifted wall-clock time. This arm only fires when
        // there is genuinely no payload to check, and only when transaction_timestamp itself
        // (not a completed_at/created_at fallback) is what's being classified — it must come
        // before, and never replace, the payload-based classification below.
        $legacyProviderArm = ($providerTimestampModeExpression !== null && $primaryTimestampColumn !== null)
            ? "WHEN {$payloadExpression} IS NULL AND {$primaryTimestampColumn} IS NOT NULL AND {$providerTimestampModeExpression} = 'local_time_with_z' THEN DATE({$timestampExpression})\n                "
            : '';

        if ($driver === 'pgsql') {
            $payloadTimestamp = "COALESCE(({$payloadExpression})::jsonb->>'transaction_timestamp', ({$payloadExpression})::jsonb#>>'{transaction,transaction_timestamp}')";

            return "CASE
                {$legacyProviderArm}WHEN {$payloadExpression} IS NOT NULL
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
                {$legacyProviderArm}WHEN {$payloadExpression} IS NOT NULL
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
            {$legacyProviderArm}WHEN {$payloadExpression} IS NOT NULL
                AND {$payloadExpression} != ''
                AND {$payloadTimestamp} IS NOT NULL
                AND {$payloadTimestamp} NOT REGEXP '(Z|[+-][0-9]{2}:?[0-9]{2})$'
            THEN DATE({$timestampExpression})
            ELSE {$localDateExpression}
        END";
    }

    protected function localReportDateExpression(string $timestampExpression): string
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

    protected function reportTimezone(): string
    {
        return config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila';
    }

    protected function payloadTimestampIsPlainLocal(mixed $payload): bool
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

    protected function resolveBusinessMoment(?Transaction $transaction, ?string $timezone = null): Carbon
    {
        $timezone ??= $this->reportTimezone();

        if ($transaction === null) {
            return Carbon::now($timezone);
        }

        $rawTransactionTimestamp = $transaction->getRawOriginal('transaction_timestamp');
        $raw = $rawTransactionTimestamp
            ?? $transaction->getRawOriginal('completed_at')
            ?? $transaction->getRawOriginal('created_at');

        if ($raw === null) {
            return Carbon::now($timezone);
        }

        $payload = $transaction->original_payload ?? null;

        if ($this->payloadTimestampIsPlainLocal($payload)) {
            // Safety net: raw payload had no explicit offset — trust the stored
            // wall-clock value as-is (mirrors the SQL CASE fallback branch).
            return Carbon::parse($raw, $timezone);
        }

        if (
            $payload === null
            && $rawTransactionTimestamp !== null
            && $this->providerUsesLocalTimeWithZ($transaction)
        ) {
            // Legacy row: no payload was ever captured, so there's no direct evidence, but
            // the pre-normalization ingestion pipeline stored transaction_timestamp verbatim
            // for every provider. A local_time_with_z provider's raw digits are therefore
            // still un-shifted local wall-clock time, not genuine UTC — trust them as-is.
            return Carbon::parse($rawTransactionTimestamp, $timezone);
        }

        // Genuinely absolute/UTC (or no better signal available) — perform the real shift.
        return Carbon::parse($raw, 'UTC')->setTimezone($timezone);
    }

    protected function providerUsesLocalTimeWithZ(Transaction $transaction): bool
    {
        return ($transaction->terminal?->provider?->timestamp_mode ?? null) === 'local_time_with_z';
    }
}
