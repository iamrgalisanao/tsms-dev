<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 002-backfill-transaction-taxes, Slice 12 (T067) — Stage 1 of 3 for the
 * orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
 *
 * Chunked, resumable, idempotent copy of every orphaned
 * (`transaction_pk IS NULL`) `transaction_taxes` row into
 * `transaction_taxes_orphan_archive`. Archive-only: this class never writes
 * to, and never even queries, `transactions` — and, per this slice's hard
 * scope boundary, never issues a DELETE statement against any table, under
 * any circumstance.
 *
 * Idempotency is via insertOrIgnore() keyed on the archive table's unique
 * `original_id` constraint, not a separate tracked resume-cursor — matching
 * this feature's established idempotency-by-construction pattern elsewhere
 * (compare TaxBackfillRunner::apply()'s `taxes()->exists()` check, though the
 * mechanism there is an existence check rather than a unique constraint).
 * This is also what makes archive() resumable "for free": re-invoking it
 * after a partial run (or after every orphan is already archived) simply
 * re-scans the same population and lets the constraint silently skip
 * whatever a prior invocation already wrote — nothing here remembers where
 * a previous run stopped.
 *
 * Bounded per chunk (FR-005/R9's chunking discipline, applied everywhere
 * else in this feature): each chunk's read + insertOrIgnore() is a single
 * short operation, never wrapped in a long-lived transaction and never
 * holding a table-wide lock.
 */
class OrphanTaxArchiver
{
    protected const SOURCE_TABLE = 'transaction_taxes';

    protected const ARCHIVE_TABLE = 'transaction_taxes_orphan_archive';

    protected const DEFAULT_CHUNK_SIZE = 1000;

    /**
     * Scan `transaction_taxes` WHERE `transaction_pk` IS NULL, ordered by
     * `id` (chunkById()), and archive every row found. One `archive_run_id`
     * (a UUID) is generated once and shared by every row this single
     * invocation writes, regardless of how many chunks that spans.
     *
     * Per-chunk counts are derived by comparing the chunk's row count
     * against insertOrIgnore()'s affected-row return value — the rows it
     * silently skipped (already archived by a prior invocation) are exactly
     * the difference between the two.
     *
     * @return array{processed: int, newly_archived: int, already_archived: int}
     */
    public function archive(int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $archiveRunId = (string) Str::uuid();

        $processed = 0;
        $newlyArchived = 0;
        $alreadyArchived = 0;

        DB::table(self::SOURCE_TABLE)
            ->whereNull('transaction_pk')
            ->chunkById($chunkSize, function ($rows) use ($archiveRunId, &$processed, &$newlyArchived, &$alreadyArchived) {
                $archivedAt = now();

                $archiveRows = $rows->map(fn ($row) => [
                    'original_id' => $row->id,
                    'transaction_pk' => $row->transaction_pk,
                    'tax_type' => $row->tax_type,
                    'amount' => $row->amount,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'archive_run_id' => $archiveRunId,
                    'archived_at' => $archivedAt,
                    'reconciled_status' => null,
                    'reason_code' => null,
                ])->all();

                $chunkCount = count($archiveRows);
                $insertedCount = DB::table(self::ARCHIVE_TABLE)->insertOrIgnore($archiveRows);

                $processed += $chunkCount;
                $newlyArchived += $insertedCount;
                $alreadyArchived += ($chunkCount - $insertedCount);
            });

        return [
            'processed' => $processed,
            'newly_archived' => $newlyArchived,
            'already_archived' => $alreadyArchived,
        ];
    }
}
