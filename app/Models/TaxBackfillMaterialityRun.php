<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per invocation of `transactions:tax-backfill-materiality` that
 * actually attempts a capture. See
 * specs/002-backfill-transaction-taxes/slice-19-materiality-brief.md.
 */
class TaxBackfillMaterialityRun extends Model
{
    use HasFactory;

    public const REPORT_CONTRACT_VERSION_CMSR_V1 = 'cmsr_v1';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'tax_backfill_materiality_runs';

    protected $fillable = [
        'snapshot_run_id',
        'report_contract_version',
        'status',
        'tenant_count',
        'month_count',
        'forced',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'tenant_count' => 'integer',
        'month_count' => 'integer',
        'forced' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function snapshotRun(): BelongsTo
    {
        return $this->belongsTo(PreBackfillSnapshotRun::class, 'snapshot_run_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(TaxBackfillMaterialityRecord::class, 'run_id');
    }
}
