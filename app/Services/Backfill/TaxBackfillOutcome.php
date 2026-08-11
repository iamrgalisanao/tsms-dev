<?php

declare(strict_types=1);

namespace App\Services\Backfill;

/**
 * Terminal states for a single `tax_backfill_records` row (T012).
 *
 * State transitions (data-model.md "New entity: backfill run/progress
 * record"): `pending -> applied | skipped_existing | quarantined | failed`.
 * Only `pending -> applied` ever writes rows to `transaction_taxes`; the
 * "pending" state itself is never persisted — a `TaxBackfillRecord` row only
 * exists once an outcome has been decided.
 *
 * This enum, and TaxBackfillReasonCode alongside it, are the persistence
 * layer's vocabulary only. Deciding *which* outcome/reason applies to a
 * given transaction is the job of the (not-yet-built) TaxBackfillRunner —
 * out of scope for this slice.
 */
enum TaxBackfillOutcome: string
{
    case Applied = 'applied';
    case SkippedExisting = 'skipped_existing';
    case Quarantined = 'quarantined';
    case Failed = 'failed';

    /**
     * Whether this outcome requires a non-null `reason_code` on the record
     * (T012). Enforced by App\Models\TaxBackfillRecord at the model layer.
     */
    public function requiresReasonCode(): bool
    {
        return match ($this) {
            self::Quarantined, self::Failed => true,
            self::Applied, self::SkippedExisting => false,
        };
    }
}
