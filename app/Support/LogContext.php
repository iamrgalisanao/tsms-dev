<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\WebappTransactionForward;

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
        if (!$tx) {
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

    public static function fromForward(?WebappTransactionForward $fwd, array $extra = []): array
    {
        if (!$fwd) {
            return self::base($extra);
        }
        $tx = $fwd->transaction ?? null;
        $ctx = [
            'batch_id' => $fwd->batch_id,
        ];
        return $tx ? self::fromTransaction($tx, array_merge($ctx, $extra)) : self::base(array_merge($ctx, $extra));
    }
}
