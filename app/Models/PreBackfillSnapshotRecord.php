<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (run, tenant, reporting month) actually captured by
 * `transactions:snapshot-pre-backfill-aggregates`. `rendered_result` is the
 * verbatim App\Services\Reports\SalesReportResult::toArray() output for that
 * pair — the whole rendered report, not a cherry-picked field. See
 * specs/002-backfill-transaction-taxes/slice-15-pre-backfill-snapshot-brief.md,
 * "Design decisions § 1" and "§ 3".
 *
 * IMPORTANT: only ever INSERT rows here, never UPDATE — the unique
 * constraint on (run_id, tenant_id, reporting_year, reporting_month) plus
 * this insert-only discipline is what makes FR-012a's source-label pinning
 * possible without any comparison logic in this slice.
 */
class PreBackfillSnapshotRecord extends Model
{
    use HasFactory;

    protected $table = 'pre_backfill_snapshot_records';

    protected $fillable = [
        'run_id',
        'tenant_id',
        'reporting_year',
        'reporting_month',
        'source',
        'rendered_result',
        'captured_at',
    ];

    protected $casts = [
        'reporting_year' => 'integer',
        'reporting_month' => 'integer',
        'rendered_result' => 'array',
        'captured_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PreBackfillSnapshotRun::class, 'run_id');
    }
}
