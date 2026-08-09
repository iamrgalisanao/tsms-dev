<?php

namespace App\Support;

use App\Models\Transaction;

/**
 * Small helper to produce a consistent logging context ("stamp").
 * Always safe to call with nulls; missing values are omitted.
 */
class LogContext
{
    public static function base(array $extra = []): array
    {
        $ctx = [
            'app' => config('app.name'),
            'env' => config('app.env'),
        ];

        return array_merge($ctx, $extra);
    }

    public static function fromTransaction(?Transaction $tx, array $extra = []): array
    {
        if (! $tx) {
            return self::base($extra);
        }
        $ctx = [
            'tenant_id' => $tx->tenant_id,
            'terminal_id' => $tx->terminal_id,
            'transaction_pk' => $tx->id,
            'transaction_id' => $tx->transaction_id,
        ];

        return self::base(array_merge($ctx, $extra));
    }

    /**
     * Canonical shape for ingestion-touchpoint log lines (intake, breaker,
     * backpressure, fairness) so every call site builds
     * tenant_id/terminal_id/route/shard/correlation_id/decision/reason the
     * same way instead of inventing its own array. Only the allowed keys
     * are taken from $context; null values are omitted rather than logged
     * as empty noise. $extra is merged in as-is for call-specific fields
     * (e.g. submission_uuid) that don't belong in the canonical shape.
     */
    public static function ingestion(array $context = [], array $extra = []): array
    {
        $allowed = ['tenant_id', 'terminal_id', 'route', 'shard', 'correlation_id', 'decision', 'reason'];

        $ctx = array_filter(
            array_intersect_key($context, array_flip($allowed)),
            fn ($value) => $value !== null
        );

        return self::base(array_merge($ctx, $extra));
    }
}
