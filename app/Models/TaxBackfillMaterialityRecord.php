<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (run, tenant, reporting month) actually compared by
 * `transactions:tax-backfill-materiality`. See
 * specs/002-backfill-transaction-taxes/slice-19-materiality-brief.md.
 *
 * IMPORTANT: only ever INSERT rows here, never UPDATE — this is the
 * immutable captured evidence SC-006 requires. The materiality *flag*
 * (threshold pass/fail) is deliberately NOT stored here; it is computed at
 * read time from other_tax_delta_amount/percent against whatever threshold
 * options the current invocation was given.
 */
class TaxBackfillMaterialityRecord extends Model
{
    use HasFactory;

    public const COMPARISON_STATUS_COMPARED = 'compared';

    public const COMPARISON_STATUS_SOURCE_MISMATCH = 'source_mismatch';

    protected $table = 'tax_backfill_materiality_records';

    protected $fillable = [
        'run_id',
        'tenant_id',
        'reporting_year',
        'reporting_month',
        'before_source',
        'after_source',
        'after_rendered_result',
        'comparison_status',
        'other_tax_before',
        'other_tax_after',
        'other_tax_delta_amount',
        'other_tax_delta_percent',
        'captured_at',
    ];

    protected $casts = [
        'reporting_year' => 'integer',
        'reporting_month' => 'integer',
        'after_rendered_result' => 'array',
        'other_tax_before' => 'float',
        'other_tax_after' => 'float',
        'other_tax_delta_amount' => 'float',
        'other_tax_delta_percent' => 'float',
        'captured_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaxBackfillMaterialityRun::class, 'run_id');
    }
}
