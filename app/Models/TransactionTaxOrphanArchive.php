<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per orphaned (`transaction_pk IS NULL`) `transaction_taxes` row
 * archived by App\Services\Backfill\OrphanTaxArchiver — 002-backfill-
 * transaction-taxes, Slice 12 (T066/T067), Stage 1 of 3 for the orphan
 * archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
 *
 * `created_at`/`updated_at` on this model are the ORIGINAL transaction_taxes
 * row's own values — copied verbatim, never "now". Eloquent's automatic
 * timestamp management is disabled ($timestamps = false) so a save() can
 * never silently overwrite them; `archived_at` is the column that actually
 * records when this archive row was written.
 *
 * `reconciled_status`/`reason_code` stay NULL for every row this slice
 * writes — Stage 2 (reconcile) is the only writer of non-null values here.
 */
class TransactionTaxOrphanArchive extends Model
{
    protected $table = 'transaction_taxes_orphan_archive';

    public $timestamps = false;

    protected $fillable = [
        'original_id',
        'transaction_pk',
        'tax_type',
        'amount',
        'created_at',
        'updated_at',
        'archive_run_id',
        'archived_at',
        'reconciled_status',
        'reason_code',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'archived_at' => 'datetime',
    ];
}
