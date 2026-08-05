<?php

namespace App\Traits;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ResolvesReportBusinessDate
{
    protected function reportDateExpression(string $timestampExpression, string $payloadExpression): string
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

        $raw = $transaction->getRawOriginal('transaction_timestamp')
            ?? $transaction->getRawOriginal('completed_at')
            ?? $transaction->getRawOriginal('created_at');

        if ($raw === null) {
            return Carbon::now($timezone);
        }

        if ($this->payloadTimestampIsPlainLocal($transaction->original_payload ?? null)) {
            return Carbon::parse($raw, $timezone);
        }

        return Carbon::parse($raw, 'UTC')->setTimezone($timezone);
    }
}
