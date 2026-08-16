<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Services\DeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 14 (T070, T070a, T071, T079) —
 * Stage 3 of 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-14-orphan-delete-brief.md.
 *
 * This is the first, and only, class in this feature that ever issues a
 * DELETE against a live table. It never deletes from, or writes anything
 * to, `transaction_taxes_orphan_archive` — that table is the permanent
 * evidence record and outlives the live rows it describes. It never
 * re-runs OrphanTaxReconciler::evaluate(); Stage 2's persisted
 * `reconciled_status`/`reason_code` on the archive table is treated as the
 * durable, sufficient record of "day D passed reconciliation."
 *
 * Two-method contract, mirroring this feature's dry-run/apply split
 * elsewhere (compare OrphanTaxReconciler::evaluate()/persist()):
 *  - preflight(Carbon $day): pure read. Runs both precondition checks and,
 *    if both pass, computes and returns the day-bound sha256 evidence hash
 *    (T079) plus a delete-preview row count. Safe to call for a dry-run
 *    report, and always the first thing delete() itself re-derives too
 *    (delete() does not trust a caller-supplied "preflight already passed"
 *    flag — it re-verifies from scratch).
 *  - delete(Carbon $day, string $token, int $chunkSize): re-verifies both
 *    preconditions, recomputes the hash fresh, and refuses (zero deletes)
 *    on any mismatch. Only once the token is confirmed does it chunk the
 *    day's archived original_ids and issue one bounded DELETE per chunk,
 *    each one keeping `transaction_pk IS NULL` as a belt-and-braces guard
 *    even though every id it operates on came from the orphan archive.
 *
 * Idempotency is deliberate, not incidental: a chunk may legitimately
 * affect fewer rows than its id count on a re-run (some of that chunk's
 * rows may already have been deleted by an earlier, interrupted delete()
 * call). `transaction_pk IS NULL` structurally guarantees a linked row is
 * never at risk regardless, so "delete rows matching this predicate,
 * however many remain" is correctly idempotent as written — unlike Stage
 * 2's persist(), this class never asserts an exact affected-row count.
 *
 * T085 (decision recorded 2026-08-11): the stakeholder decision on
 * 2026-06-13's 216 unrecoverable transactions' orphan rows is archive
 * (durable, verified, `reason_code = 'no_replacement_exists'`) and then
 * **delete** from the live table, same as every other day — reversing the
 * original "retain live forever" default (FR-015b, T070a). No special case
 * for 2026-06-13 exists anywhere in this class: once its rows carry a
 * non-null `reconciled_status` (residual, per Stage 2's `no_replacement_exists`
 * classification), `delete()` removes them the same way as any other day's
 * `reconciled`/`timestamp_out_of_tolerance` rows.
 */
class OrphanTaxDeleter
{
    protected const ARCHIVE_TABLE = 'transaction_taxes_orphan_archive';

    protected const SOURCE_TABLE = 'transaction_taxes';

    protected const DEFAULT_CHUNK_SIZE = 1000;

    public const REFUSAL_ARCHIVE_INCOMPLETE = 'archive_incomplete';

    public const REFUSAL_RECONCILIATION_NOT_PASSED = 'reconciliation_not_passed';

    public const REFUSAL_TOKEN_MISMATCH = 'token_mismatch';

    public function __construct(
        protected DeadlockRetryService $retryService
    ) {}

    /**
     * Pure read. Runs both precondition checks for day $day and, only if
     * both pass, computes the day-bound evidence hash (T079) and a
     * delete-preview row count. Never writes anything, under any outcome.
     *
     * @return array{
     *     day: string,
     *     passed: bool,
     *     refusal_reason: string|null,
     *     refusal_message: string|null,
     *     preconditions: array{
     *         archive_complete: bool,
     *         missing_archive_count: int,
     *         reconciliation_passed: bool,
     *         unreconciled_count: int,
     *     },
     *     authorization_token: string|null,
     *     preview_count: int|null,
     * }
     */
    public function preflight(Carbon $day): array
    {
        $dayKey = $day->copy()->startOfDay()->format('Y-m-d');
        [$dayStart, $dayEnd] = $this->dayBounds($day);

        $missingArchiveCount = $this->countLiveOrphansMissingArchive($dayStart, $dayEnd);

        if ($missingArchiveCount > 0) {
            return $this->refusalPreflightResult(
                $dayKey,
                self::REFUSAL_ARCHIVE_INCOMPLETE,
                "Day {$dayKey} cannot be deleted: {$missingArchiveCount} live orphan row(s) have no matching archive row. Stage 1 (archive) has not fully covered this day yet.",
                false,
                $missingArchiveCount,
                true,
                0
            );
        }

        $unreconciledCount = $this->countUnreconciledArchiveRows($dayStart, $dayEnd);

        if ($unreconciledCount > 0) {
            return $this->refusalPreflightResult(
                $dayKey,
                self::REFUSAL_RECONCILIATION_NOT_PASSED,
                "Day {$dayKey} cannot be deleted: {$unreconciledCount} archived row(s) have no reconciled_status. Day {$dayKey} was never reconciled, or its reconciliation halted.",
                true,
                0,
                false,
                $unreconciledCount
            );
        }

        return [
            'day' => $dayKey,
            'passed' => true,
            'refusal_reason' => null,
            'refusal_message' => null,
            'preconditions' => [
                'archive_complete' => true,
                'missing_archive_count' => 0,
                'reconciliation_passed' => true,
                'unreconciled_count' => 0,
            ],
            'authorization_token' => $this->computeHash($dayKey, $dayStart, $dayEnd),
            'preview_count' => $this->previewCount($dayStart, $dayEnd),
        ];
    }

    /**
     * Re-verifies both preconditions from scratch, recomputes the evidence
     * hash fresh, and refuses (zero deletes) unless it matches $token
     * exactly. Only then chunks day $day's archived original_ids
     * (ascending, identical query to the hash computation) and issues one
     * bounded `DELETE ... WHERE id IN (<chunk>) AND transaction_pk IS NULL`
     * per chunk — never a single unbounded DELETE.
     *
     * @return array{
     *     day: string,
     *     authorized: bool,
     *     refusal_reason: string|null,
     *     refusal_message: string|null,
     *     token_verified: bool|null,
     *     chunks_processed: int,
     *     rows_deleted: int,
     *     already_deleted: int,
     * }
     */
    public function delete(Carbon $day, string $token, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $dayKey = $day->copy()->startOfDay()->format('Y-m-d');
        [$dayStart, $dayEnd] = $this->dayBounds($day);

        $missingArchiveCount = $this->countLiveOrphansMissingArchive($dayStart, $dayEnd);

        if ($missingArchiveCount > 0) {
            return $this->refusalDeleteResult(
                $dayKey,
                self::REFUSAL_ARCHIVE_INCOMPLETE,
                "Day {$dayKey} cannot be deleted: {$missingArchiveCount} live orphan row(s) have no matching archive row. Stage 1 (archive) has not fully covered this day yet.",
                null
            );
        }

        $unreconciledCount = $this->countUnreconciledArchiveRows($dayStart, $dayEnd);

        if ($unreconciledCount > 0) {
            return $this->refusalDeleteResult(
                $dayKey,
                self::REFUSAL_RECONCILIATION_NOT_PASSED,
                "Day {$dayKey} cannot be deleted: {$unreconciledCount} archived row(s) have no reconciled_status. Day {$dayKey} was never reconciled, or its reconciliation halted.",
                null
            );
        }

        $freshHash = $this->computeHash($dayKey, $dayStart, $dayEnd);

        if (! hash_equals($freshHash, $token)) {
            return $this->refusalDeleteResult(
                $dayKey,
                self::REFUSAL_TOKEN_MISMATCH,
                "Day {$dayKey} cannot be deleted: the supplied authorization token does not match the current evidence hash for this day.",
                false
            );
        }

        $ids = DB::table(self::ARCHIVE_TABLE)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->orderBy('original_id')
            ->pluck('original_id')
            ->all();

        $chunksProcessed = 0;
        $rowsDeleted = 0;
        $alreadyDeleted = 0;

        foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk) {
            // Never a single unbounded DELETE (T070, FR-015): each chunk is
            // its own bounded statement. `transaction_pk IS NULL` is kept as
            // a belt-and-braces guard even though every id here came from
            // the orphan archive — it must never be removed as an
            // "optimization."
            //
            // Slice 17 (T100): wrapped in DeadlockRetryService::
            // withDeadlockRetry(), matching TaxBackfillRunner's own usage
            // shape exactly (default $wrapInTransaction = true — each retry
            // attempt gets its own fresh transaction). Unlike
            // OrphanTaxReconciler::persist(), there is no outer
            // all-or-nothing transaction here to preserve: this class's own
            // idempotency guarantee (a chunk may legitimately affect fewer
            // rows than its id count on a retry/re-run) already tolerates a
            // fresh-transaction-per-attempt retry cleanly.
            $affected = $this->retryService->withDeadlockRetry(function () use ($chunk) {
                return DB::table(self::SOURCE_TABLE)
                    ->whereIn('id', $chunk)
                    ->whereNull('transaction_pk')
                    ->delete();
            });

            $chunksProcessed++;
            $rowsDeleted += $affected;
            // A chunk may legitimately affect fewer rows than its id count
            // on an idempotent re-run (some of this chunk's rows may already
            // have been deleted by an earlier, interrupted delete() call) —
            // that is success, not an error. Never assert an exact count
            // here the way Stage 2's persist() does.
            $alreadyDeleted += (count($chunk) - $affected);
        }

        return [
            'day' => $dayKey,
            'authorized' => true,
            'refusal_reason' => null,
            'refusal_message' => null,
            'token_verified' => true,
            'chunks_processed' => $chunksProcessed,
            'rows_deleted' => $rowsDeleted,
            'already_deleted' => $alreadyDeleted,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dayBounds(Carbon $day): array
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        return [$dayStart, $dayEnd];
    }

    /**
     * Precondition 1: every row currently live in `transaction_taxes` with
     * `transaction_pk IS NULL` and `created_at` in the identical strict
     * `[dayStart, dayEnd)` range Stage 2 uses (no buffer) must have a
     * matching row in `transaction_taxes_orphan_archive`
     * (`archive.original_id = live.id`). Counts the ones that don't.
     */
    protected function countLiveOrphansMissingArchive(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table(self::SOURCE_TABLE.' as t')
            ->whereNull('t.transaction_pk')
            ->where('t.created_at', '>=', $dayStart)
            ->where('t.created_at', '<', $dayEnd)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from(self::ARCHIVE_TABLE.' as a')
                    ->whereColumn('a.original_id', 't.id');
            })
            ->count();
    }

    /**
     * Precondition 2: every row in `transaction_taxes_orphan_archive` with
     * `created_at` in `[dayStart, dayEnd)` must have a non-NULL
     * `reconciled_status`. Counts the ones that don't.
     */
    protected function countUnreconciledArchiveRows(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table(self::ARCHIVE_TABLE)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->whereNull('reconciled_status')
            ->count();
    }

    /**
     * T079's evidence hash. Computed purely from `transaction_taxes_orphan_
     * archive` (permanent, append-only-then-status-updated-once, never
     * touched by delete() itself) — never from `transaction_taxes` (about to
     * be mutated) or `tax_backfill_records` (already consumed by Stage 2).
     *
     * The day is part of the hashed input, not just an argument to the
     * function: the canonical string is prefixed with $dayKey before the
     * row tuples, so the hash is self-describing and structurally bound to
     * the specific day it authorizes — passing day D's token against
     * `--day=D+1` fails the comparison structurally, not by luck.
     *
     * Explicitly sorted by `original_id` (never relies on insertion/scan
     * order) and built from a fixed string format (never `json_encode`,
     * whose key-ordering behavior is a needless determinism risk here).
     *
     * This exact code shape is prescribed by
     * specs/002-backfill-transaction-taxes/slice-14-orphan-delete-brief.md
     * ("Evidence hash") — do not substitute a different serialization or
     * hash algorithm.
     */
    protected function computeHash(string $dayKey, Carbon $dayStart, Carbon $dayEnd): string
    {
        $rows = DB::table(self::ARCHIVE_TABLE)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->orderBy('original_id')
            ->get(['original_id', 'reconciled_status', 'reason_code']);

        $canonical = $dayKey.'|'.$rows->map(fn ($r) => "{$r->original_id}:{$r->reconciled_status}:".($r->reason_code ?? 'NULL'))->implode('|');

        return hash('sha256', $canonical);
    }

    /**
     * The delete-preview row count shown by a `--phase=delete` dry-run: the
     * same population delete() would chunk over (day D's archived
     * original_ids), counted rather than deleted.
     */
    protected function previewCount(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table(self::ARCHIVE_TABLE)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->count();
    }

    protected function refusalPreflightResult(
        string $dayKey,
        string $refusalReason,
        string $refusalMessage,
        bool $archiveComplete,
        int $missingArchiveCount,
        bool $reconciliationPassed,
        int $unreconciledCount
    ): array {
        return [
            'day' => $dayKey,
            'passed' => false,
            'refusal_reason' => $refusalReason,
            'refusal_message' => $refusalMessage,
            'preconditions' => [
                'archive_complete' => $archiveComplete,
                'missing_archive_count' => $missingArchiveCount,
                'reconciliation_passed' => $reconciliationPassed,
                'unreconciled_count' => $unreconciledCount,
            ],
            'authorization_token' => null,
            'preview_count' => null,
        ];
    }

    /**
     * @param  bool|null  $tokenVerified  null when refused before the token
     *                                    was even compared (a precondition
     *                                    already failed); false when the
     *                                    token was compared and did not
     *                                    match.
     */
    protected function refusalDeleteResult(string $dayKey, string $refusalReason, string $refusalMessage, ?bool $tokenVerified): array
    {
        return [
            'day' => $dayKey,
            'authorized' => false,
            'refusal_reason' => $refusalReason,
            'refusal_message' => $refusalMessage,
            'token_verified' => $tokenVerified,
            'chunks_processed' => 0,
            'rows_deleted' => 0,
            'already_deleted' => 0,
        ];
    }
}
