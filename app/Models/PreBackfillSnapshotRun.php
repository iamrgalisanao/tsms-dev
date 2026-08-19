<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per invocation of `transactions:snapshot-pre-backfill-aggregates`
 * that actually attempts a capture. Correlates every
 * App\Models\PreBackfillSnapshotRecord row to the run that produced it. See
 * specs/002-backfill-transaction-taxes/slice-15-pre-backfill-snapshot-brief.md,
 * "Design decisions § 1" and "§ 5".
 */
class PreBackfillSnapshotRun extends Model
{
    use HasFactory;

    /**
     * What this snapshot is for — a fixed identifier, never CLI-configurable
     * in this slice. Exists so this window is never "permanently owned" by
     * the first run that happened to capture it (Design decision 1).
     */
    public const TYPE_PRE_BACKFILL_RENDERED_AGGREGATE = 'pre_backfill_rendered_aggregate';

    /**
     * Which version of App\Services\Reports\SalesReportDataService::getCmsrReportData()'s
     * output shape/semantics this run's calls were made under — also a fixed
     * class constant, never CLI-configurable. If that report path's formula
     * or output shape ever changes, a future run tags itself with a new
     * version rather than silently colliding with, or being blocked by, an
     * old run's baseline captured under different semantics.
     */
    public const REPORT_CONTRACT_VERSION_CMSR_V1 = 'cmsr_v1';

    public const REPORT_CONTRACT_VERSION_CMSR_V2 = 'cmsr_v2';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'pre_backfill_snapshot_runs';

    protected $fillable = [
        'snapshot_type',
        'report_contract_version',
        'window_start',
        'window_end',
        'status',
        'tenant_count',
        'month_count',
        'forced',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'tenant_count' => 'integer',
        'month_count' => 'integer',
        'forced' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(PreBackfillSnapshotRecord::class, 'run_id');
    }
}
