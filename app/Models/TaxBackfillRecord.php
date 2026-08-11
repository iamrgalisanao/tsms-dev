<?php

namespace App\Models;

use App\Services\Backfill\TaxBackfillOutcome;
use App\Services\Backfill\TaxBackfillReasonCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per transaction scanned by a tax-backfill run, whether or not it
 * ended up written — the durable audit/quarantine record this slice exists
 * to build. See
 * specs/002-backfill-transaction-taxes/data-model.md, "New entity: backfill
 * run/progress record" (row-level table).
 *
 * IMPORTANT: all writes to this table MUST go through this model, never a
 * raw `DB::table('tax_backfill_records')` insert/update — the outcome /
 * reason-code invariant in boot() below is an Eloquent lifecycle hook and is
 * enforced only on this write path. A raw DB::table() write silently
 * bypasses it. There is no runner yet to violate this (that's a later
 * slice), but it matters the moment one exists, especially if it's ever
 * tempted to reach for a bulk DB::table()->insert() for performance.
 */
class TaxBackfillRecord extends Model
{
    use HasFactory;

    protected $table = 'tax_backfill_records';

    protected $fillable = [
        'run_id',
        'transaction_pk',
        'tenant_id',
        'reconstructed_tax_rows',
        'had_linked_rows_before',
        'outcome',
        'reason_code',
        'archive_reference',
    ];

    protected $casts = [
        'reconstructed_tax_rows' => 'array',
        'had_linked_rows_before' => 'boolean',
        'archive_reference' => 'array',
    ];

    /**
     * Enforce T012: `reason_code` is required whenever `outcome` is
     * `quarantined` or `failed`. Mirrors the invariant-guard pattern used by
     * App\Models\TransactionJob's boot() (throws rather than silently
     * defaulting) — the original defect this whole feature exists to fix
     * was exactly this kind of silently-dropped/ignored data.
     *
     * For `quarantined` specifically, `reason_code` is further validated
     * against TaxBackfillReasonCode::tryFrom() — presence alone isn't
     * enough, since a typo'd value (e.g. 'mssing_paylod') would otherwise
     * pass silently. `failed` is intentionally NOT held to that same closed
     * set: TaxBackfillReasonCode only enumerates the two quarantine reasons
     * (T013); failure-specific codes are future work per that enum's own
     * docblock, so `failed` gets presence-only checking for now.
     */
    protected static function boot()
    {
        parent::boot();

        $guard = function (self $record) {
            $outcome = TaxBackfillOutcome::tryFrom((string) $record->outcome);

            if ($outcome === null) {
                throw new \InvalidArgumentException(
                    'TaxBackfillRecord::outcome must be one of: '.
                    implode(', ', array_map(fn ($case) => $case->value, TaxBackfillOutcome::cases())).
                    ". Got: {$record->outcome}"
                );
            }

            if ($outcome->requiresReasonCode() && empty($record->reason_code)) {
                throw new \InvalidArgumentException(
                    "TaxBackfillRecord::reason_code is required when outcome is '{$outcome->value}'."
                );
            }

            if ($outcome === TaxBackfillOutcome::Quarantined
                && $record->reason_code !== null
                && TaxBackfillReasonCode::tryFrom((string) $record->reason_code) === null) {
                throw new \InvalidArgumentException(
                    "TaxBackfillRecord::reason_code '{$record->reason_code}' is not a valid quarantine reason. Must be one of: ".
                    implode(', ', array_map(fn ($case) => $case->value, TaxBackfillReasonCode::cases())).'.'
                );
            }
        };

        static::creating($guard);
        static::updating($guard);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaxBackfillRun::class, 'run_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_pk', 'id');
    }
}
