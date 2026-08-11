<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per invocation of the tax-backfill run (TaxBackfillRunner, not
 * yet built). Correlates every App\Models\TaxBackfillRecord row to the run
 * that produced it. See
 * specs/002-backfill-transaction-taxes/data-model.md, "New entity: backfill
 * run/progress record".
 */
class TaxBackfillRun extends Model
{
    use HasFactory;

    public const MODE_DRY_RUN = 'dry-run';

    public const MODE_APPLY = 'apply';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INTERRUPTED = 'interrupted';

    public const STATUS_FAILED = 'failed';

    protected $table = 'tax_backfill_runs';

    protected $fillable = [
        'window_start',
        'window_end',
        'mode',
        'scanned_count',
        'reconstructed_count',
        'skipped_existing_count',
        'quarantined_count',
        'failed_count',
        'status',
        'operator',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'scanned_count' => 'integer',
        'reconstructed_count' => 'integer',
        'skipped_existing_count' => 'integer',
        'quarantined_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(TaxBackfillRecord::class, 'run_id');
    }
}
