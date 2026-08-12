<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per invocation of `transactions:capture-integrity-evidence` that
 * persists (--apply). See
 * specs/002-backfill-transaction-taxes/slice-16-integrity-evidence-capture-brief.md,
 * "Design decisions § 1".
 *
 * Deliberately independent of App\Models\PreBackfillSnapshotRun and
 * App\Models\PreBackfillSnapshotRecord (Slice 15) — different evidence,
 * different purpose, no shared table or relationship.
 */
class PreRunIntegrityCapture extends Model
{
    use HasFactory;

    /**
     * This slice only ever writes PHASE_PRE_RUN. PHASE_POST_RUN exists so
     * T076 can record its own post-run capture through this same table
     * without a schema change — this slice never writes it.
     */
    public const PHASE_PRE_RUN = 'pre_run';

    public const PHASE_POST_RUN = 'post_run';

    protected $table = 'pre_run_integrity_captures';

    /**
     * No created_at/updated_at columns on this table — captured_at is the
     * single, explicit timestamp for this row (see the migration).
     */
    public $timestamps = false;

    protected $fillable = [
        'window_start',
        'window_end',
        'phase',
        'duplicate_check_summary',
        'integrity_report',
        'captured_at',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'duplicate_check_summary' => 'array',
        'captured_at' => 'datetime',
    ];
}
