<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 002-backfill-transaction-taxes, Slice 13 (T069, T071-partial) — Stage 2 of
 * 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-13-orphan-reconcile-brief.md.
 *
 * Day-scoped, read-then-write reconciliation of the orphan population
 * (`transaction_taxes` rows with `transaction_pk IS NULL`) already copied
 * into `transaction_taxes_orphan_archive` by Stage 1
 * (App\Services\Backfill\OrphanTaxArchiver, Slice 12). This class never
 * deletes anything and never touches `transaction_taxes` at all beyond
 * reading it — its only write path is `evaluate()`'s companion `persist()`,
 * which UPDATEs `transaction_taxes_orphan_archive.reconciled_status`/
 * `reason_code` for a day whose verdict passed.
 *
 * Two-method contract, mirroring this feature's dry-run/apply split
 * elsewhere (compare TaxBackfillRunner::dryRun()/apply()):
 *  - evaluate(Carbon $day): pure read. Runs the brief's Steps 1-5 in full
 *    and returns one result array describing the day's verdict — safe to
 *    call for a dry-run report, and always called first even for --apply
 *    (there is no separate "compute" step apply() re-runs; it reuses
 *    evaluate()'s own output).
 *  - persist(array $evaluation): the brief's Step 6. Refuses (throws) unless
 *    the given evaluation's `passed` is exactly true, so a halted or
 *    pending-guard-refused day can never be partially written — "compute
 *    the full day's verdict in memory first, only write archive updates if
 *    the day as a whole passes" (brief, Step 6).
 *
 * No per-row transaction attribution anywhere in this class (research.md
 * V4, reiterated in the brief's scope contract): every classification
 * decision is a COUNT within a (`tax_type`, `amount`, day) bucket. The
 * bucket-matching algorithm (matchBucket()) operates on bare
 * `['id' => int, 'ts' => Carbon]` arrays for both sides and never reads or
 * returns anything from the `transactions` table — only loadOrphans()'s
 * sibling loadInserted() reads `transaction_taxes`, and neither ever joins
 * to `transactions`. The only two methods that touch `transactions` at all
 * are countPendingTransactions() (Step 2's precondition guard) and
 * countMissingPayload()/countAffectedTransactions() (Step 5's day-level
 * cross-check) — both day-level aggregates over `tax_backfill_records`,
 * never keyed to an individual orphan row.
 */
class OrphanTaxReconciler
{
    /**
     * FR-014's decided tolerance window (spec.md, research.md V5,
     * 2026-08-12): a reconstructed row and an orphan match when
     * `0 <= orphan.created_at - inserted.created_at <= 10` seconds. Used
     * exclusively inside matchBucket()'s window comparison — it is NOT
     * reused as a query buffer anywhere (corrected 2026-08-12, Code
     * Reviewer finding; see loadOrphans()/evaluate()).
     */
    protected const TOLERANCE_SECONDS = 10;

    /**
     * persist()'s per-status-group UPDATE batch size (corrected
     * 2026-08-12, Code Reviewer finding): a single day can carry 100,000+
     * orphan rows (research.md V4 — 06-25 alone has 132,672), so a group's
     * ids must never be passed to a single unbounded `whereIn(...)`.
     * Matches OrphanTaxArchiver::DEFAULT_CHUNK_SIZE's existing convention.
     */
    protected const PERSIST_CHUNK_SIZE = 1000;

    protected const ARCHIVE_TABLE = 'transaction_taxes_orphan_archive';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_RESIDUAL = 'residual';

    public const REASON_TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';

    public const REASON_NO_REPLACEMENT_EXISTS = 'no_replacement_exists';

    public const REASON_ORPHAN_CONTENT_MISMATCH = 'orphan_content_mismatch';

    /**
     * Run Steps 1-5 for day $day and return the full verdict, read-only.
     * Never writes anything, under any outcome — safe to call for a
     * dry-run, and always the first step of an --apply run too.
     *
     * @return array{
     *     day: string,
     *     passed: bool,
     *     halt_reason: string|null,
     *     halt_message: string|null,
     *     precondition: array{passed: bool, pending_count: int},
     *     totals: array{
     *         orphans: int,
     *         inserted: int,
     *         reconciled: int,
     *         timestamp_out_of_tolerance: int,
     *         no_replacement_exists: int,
     *         orphan_content_mismatch: int,
     *     },
     *     content_gap: array{
     *         actual: int,
     *         expected: int|null,
     *         ratio: int|null,
     *         missing_payload_count: int|null,
     *         total_affected_transactions: int|null,
     *     },
     *     verdicts: array<int, array{status: string, reason_code: string|null}>,
     * }
     */
    public function evaluate(Carbon $day): array
    {
        $day = $day->copy()->startOfDay();
        $dayKey = $day->format('Y-m-d');
        $dayStart = $day->copy();
        $dayEnd = $dayStart->copy()->addDay();

        // Step 2 — precondition guard. Refuse before touching a single
        // orphan/inserted row if any day-D transaction hasn't yet been
        // given a terminal tax_backfill_records outcome. A TaxBackfillRecord
        // row only ever exists once an outcome has been decided (pending is
        // never persisted, per TaxBackfillOutcome's own docblock) — so
        // "pending" operationally means "no tax_backfill_records row at all
        // for this transaction yet."
        $pendingCount = $this->countPendingTransactions($dayStart, $dayEnd);

        if ($pendingCount > 0) {
            return $this->preconditionHaltResult($dayKey, $pendingCount);
        }

        // Step 1 — load candidates. Both orphans and inserted/reconstructed
        // rows use the identical strict [dayStart, dayEnd) range — NO
        // buffer (corrected 2026-08-12, Code Reviewer finding: a +10s
        // upper-bound buffer on the orphan query let an orphan created just
        // after midnight on day D+1 be loaded — and given a verdict — by
        // BOTH day D's evaluation (via the buffer) and day D+1's own
        // evaluation, and persist()'s unconditional
        // `UPDATE ... WHERE original_id = <id>` meant whichever day's
        // --apply ran second silently clobbered the other's verdict for
        // that row, with no error). See
        // specs/002-backfill-transaction-taxes/slice-13-orphan-reconcile-brief.md
        // Step 1 for the accepted trade-off: a genuine cross-midnight
        // straggler now surfaces as a small, unexplained content-gap
        // surplus on the FOLLOWING day and correctly halts that day for
        // human review, rather than silently guessing which day it
        // belongs to.
        $orphans = $this->loadOrphans($dayStart, $dayEnd);
        $inserted = $this->loadInserted($dayStart, $dayEnd);

        $orphanBuckets = $this->bucketRows($orphans);
        $insertedBuckets = $this->bucketRows($inserted);

        $reconciledIds = [];
        $toleranceIds = [];
        $contentGapIds = [];

        foreach (array_keys($orphanBuckets + $insertedBuckets) as $bucketKey) {
            $bucketOrphans = $orphanBuckets[$bucketKey] ?? [];
            $bucketInserts = $insertedBuckets[$bucketKey] ?? [];

            // Step 3 — per-bucket tolerance matching.
            $match = $this->matchBucket($bucketOrphans, $bucketInserts);

            $reconciledIds = array_merge($reconciledIds, $match['matched_orphan_ids']);

            // Step 4 — residual split within this bucket. Bounded by
            // min(unpaired_orphan_count, unpaired_insert_count) — this is
            // exactly the over-classification guard the brief calls out: a
            // bucket with far more unpaired orphans than unpaired inserted
            // rows must never label more than the insert side's count as
            // "timestamp_out_of_tolerance."
            $unmatchedOrphans = $match['unmatched_orphans'];
            $unpairedOrphanCount = count($unmatchedOrphans);
            $unpairedInsertCount = $match['insert_count'] - count($match['matched_orphan_ids']);
            $toleranceOverflow = min($unpairedOrphanCount, $unpairedInsertCount);

            foreach (array_slice($unmatchedOrphans, 0, $toleranceOverflow) as $row) {
                $toleranceIds[] = $row['id'];
            }

            foreach (array_slice($unmatchedOrphans, $toleranceOverflow) as $row) {
                $contentGapIds[] = $row['id'];
            }
        }

        return $this->finishEvaluation($dayKey, $dayStart, $dayEnd, count($orphans), count($inserted), $reconciledIds, $toleranceIds, $contentGapIds);
    }

    /**
     * Step 6 — persist verdicts. Refuses to write anything unless
     * $evaluation['passed'] === true: a halted (content-mismatch) or
     * precondition-refused day must never have any of its rows touched,
     * "all-or-nothing" per the brief. The whole write is one DB transaction,
     * so even an unexpected failure partway through a passing day's update
     * leaves zero rows changed rather than a partial write.
     *
     * Each status/reason_code group's ids are chunked into batches of
     * PERSIST_CHUNK_SIZE (corrected 2026-08-12, Code Reviewer finding — a
     * single day can carry 100,000+ orphan rows, so no group's ids may be
     * passed to a single unbounded `whereIn(...)`). After each chunk's
     * UPDATE, the affected-row count is asserted to equal the chunk's id
     * count; a mismatch throws inside the enclosing DB::transaction() so
     * the whole day rolls back rather than silently reporting success on a
     * partial write (corrected 2026-08-12, Code Reviewer finding).
     *
     * Slice 17 (T100) originally wrapped each chunk's UPDATE in
     * DeadlockRetryService::withDeadlockRetry($callback, 5, false) —
     * $wrapInTransaction = false — reasoning that a "Lock wait timeout
     * exceeded" error does not abort the enclosing transaction, so retrying
     * just that chunk's statement in place was safe. That reasoning silently
     * broke for a *genuine* deadlock (the other error
     * isRetryableDeadlock() treats identically): MySQL force-rolls-back the
     * entire connection-level transaction server-side the moment InnoDB
     * picks a victim, but PDO/Laravel's own transaction-depth counter
     * (`$this->transactions`) never learns that happened. Concretely: if
     * chunk 3 of 5 hit a genuine deadlock, chunks 1-2 were already discarded
     * server-side, yet the old code just retried chunk 3's statement
     * directly (succeeding, since the connection had silently fallen back to
     * autocommit) and carried on through chunks 4-5 the same way — the
     * eventual `commit()` on the enclosing DB::transaction() below was then
     * a no-op over a transaction that no longer existed. Net effect: a
     * silent partial write (chunks 1-2 lost, 3-5 committed piecemeal) with
     * no exception thrown, in direct violation of this method's
     * all-or-nothing-per-day guarantee. Found and fixed during Slice 17
     * review before it ever shipped.
     *
     * The fix: drop DeadlockRetryService entirely from this method and let
     * the enclosing `DB::transaction($callback, 5)` use Laravel's own
     * native retry (its second, `$attempts` argument — see
     * Illuminate\Database\Concerns\ManagesTransactions::transaction()).
     * That native retry is safe for exactly the reason the per-chunk
     * approach wasn't: `handleTransactionException()` unconditionally calls
     * `$this->rollBack()` (a real, immediate rollback of whatever is left of
     * the transaction) *before* deciding whether to retry, and only then
     * re-invokes the *entire* closure from scratch — every chunk, including
     * ones that already "succeeded" this attempt. A retry is always
     * "roll back everything, start over," never "keep going from where we
     * were," so it can never produce a partial commit no matter which chunk
     * the deadlock/lock-wait hits. `Illuminate\Database\
     * DetectsConcurrencyErrors::causedByConcurrencyError()` matches both
     * "Deadlock found when trying to get lock" and "Lock wait timeout
     * exceeded; try restarting transaction" — the same two phrases this
     * brief's lower innodb_lock_wait_timeout pairing targets (see
     * IdleTransactionWatchdog's docblock and the brief's Grounding section)
     * — so no coverage is lost by moving off DeadlockRetryService here. If
     * retries are exhausted, Laravel rethrows the original exception out of
     * `DB::transaction()` exactly as before, rolling back the whole day.
     */
    public function persist(array $evaluation): void
    {
        if (($evaluation['passed'] ?? false) !== true) {
            throw new RuntimeException(
                "OrphanTaxReconciler::persist() refuses to write day '{$evaluation['day']}': its evaluation did not pass (halt_reason: ".
                ($evaluation['halt_reason'] ?? 'unknown').'). Call evaluate() and check [\'passed\'] before persisting.'
            );
        }

        $verdicts = $evaluation['verdicts'];

        if ($verdicts === []) {
            return;
        }

        // Grouped into one UPDATE per distinct (status, reason_code) pair —
        // functionally identical to the brief's per-row
        // "UPDATE ... WHERE original_id = <id>" wording (every row still
        // receives exactly that update), just batched for efficiency.
        $groups = [];

        foreach ($verdicts as $originalId => $verdict) {
            $groupKey = $verdict['status'].'|'.($verdict['reason_code'] ?? '');
            $groups[$groupKey]['status'] ??= $verdict['status'];
            $groups[$groupKey]['reason_code'] ??= $verdict['reason_code'];
            $groups[$groupKey]['ids'][] = $originalId;
        }

        $day = $evaluation['day'];

        DB::transaction(function () use ($groups, $day) {
            foreach ($groups as $group) {
                foreach (array_chunk($group['ids'], self::PERSIST_CHUNK_SIZE) as $chunk) {
                    $affected = DB::table(self::ARCHIVE_TABLE)
                        ->whereIn('original_id', $chunk)
                        ->update([
                            'reconciled_status' => $group['status'],
                            'reason_code' => $group['reason_code'],
                        ]);

                    if ($affected !== count($chunk)) {
                        throw new RuntimeException(
                            "OrphanTaxReconciler::persist() for day '{$day}' expected to update ".count($chunk).
                            " archive row(s) for status='{$group['status']}' reason_code='".($group['reason_code'] ?? 'NULL').
                            "', but only {$affected} row(s) were affected. Every orphan should already have a Stage-1 archive row -- ".
                            'refusing to report success on a partial write; the whole day\'s persist() has been rolled back.'
                        );
                    }
                }
            }
        }, 5);
    }

    /**
     * Step 5 (and assembling the final result) once Steps 1-4 have produced
     * this day's reconciled/tolerance/content-gap orphan-id lists.
     */
    protected function finishEvaluation(
        string $dayKey,
        Carbon $dayStart,
        Carbon $dayEnd,
        int $totalOrphans,
        int $totalInserted,
        array $reconciledIds,
        array $toleranceIds,
        array $contentGapIds
    ): array {
        $actualContentGap = count($contentGapIds);

        $contentGapInfo = [
            'actual' => $actualContentGap,
            'expected' => null,
            'ratio' => null,
            'missing_payload_count' => null,
            'total_affected_transactions' => null,
        ];

        // Nothing to explain: every orphan in this day was either
        // reconciled or fell into the bounded tolerance-overflow bucket.
        // Step 5's cross-check exists only to adjudicate the content-gap
        // category, so with zero content-gap orphans there is nothing for
        // it to adjudicate and the day trivially passes.
        if ($actualContentGap === 0) {
            return $this->buildResult($dayKey, true, null, null, $totalOrphans, $totalInserted, $reconciledIds, $toleranceIds, $contentGapIds, [], $contentGapInfo);
        }

        $missingPayloadCount = $this->countMissingPayload($dayStart, $dayEnd);
        $totalAffected = $this->countAffectedTransactions($dayStart, $dayEnd);

        $contentGapInfo['missing_payload_count'] = $missingPayloadCount;
        $contentGapInfo['total_affected_transactions'] = $totalAffected;

        // ratio(D) MUST be a whole number for the cross-check to run at
        // all (brief, Step 5) — a non-integral ratio (including the
        // division-by-zero case of no affected-transaction population at
        // all) fails closed rather than rounding or approximating.
        if ($totalAffected === 0 || $totalOrphans % $totalAffected !== 0) {
            $ratioDisplay = $totalAffected === 0
                ? 'N/A (no affected-transaction population for this day)'
                : 'N/A (ratio(D) is non-integral)';

            $message = "Day {$dayKey} halted: actual_content_gap={$actualContentGap}, expected_content_gap={$ratioDisplay}.";

            return $this->buildResult($dayKey, false, self::REASON_ORPHAN_CONTENT_MISMATCH, $message, $totalOrphans, $totalInserted, $reconciledIds, $toleranceIds, [], $contentGapIds, $contentGapInfo);
        }

        $ratio = intdiv($totalOrphans, $totalAffected);
        $expectedContentGap = $missingPayloadCount * $ratio;

        $contentGapInfo['ratio'] = $ratio;
        $contentGapInfo['expected'] = $expectedContentGap;

        if ($expectedContentGap === $actualContentGap) {
            // Step 5.3 — a day-level verdict, not a per-row identification:
            // no claim is made about which specific orphan belongs to which
            // quarantined transaction, only that the day's aggregate counts
            // agree.
            return $this->buildResult($dayKey, true, null, null, $totalOrphans, $totalInserted, $reconciledIds, $toleranceIds, $contentGapIds, [], $contentGapInfo);
        }

        $message = "Day {$dayKey} halted: actual_content_gap={$actualContentGap}, expected_content_gap={$expectedContentGap}.";

        return $this->buildResult($dayKey, false, self::REASON_ORPHAN_CONTENT_MISMATCH, $message, $totalOrphans, $totalInserted, $reconciledIds, $toleranceIds, [], $contentGapIds, $contentGapInfo);
    }

    /**
     * @param  array<int>  $reconciledIds
     * @param  array<int>  $toleranceIds
     * @param  array<int>  $noReplacementIds
     * @param  array<int>  $mismatchIds
     */
    protected function buildResult(
        string $dayKey,
        bool $passed,
        ?string $haltReason,
        ?string $haltMessage,
        int $totalOrphans,
        int $totalInserted,
        array $reconciledIds,
        array $toleranceIds,
        array $noReplacementIds,
        array $mismatchIds,
        array $contentGapInfo
    ): array {
        $verdicts = [];

        foreach ($reconciledIds as $id) {
            $verdicts[$id] = ['status' => self::STATUS_RECONCILED, 'reason_code' => null];
        }

        foreach ($toleranceIds as $id) {
            $verdicts[$id] = ['status' => self::STATUS_RESIDUAL, 'reason_code' => self::REASON_TIMESTAMP_OUT_OF_TOLERANCE];
        }

        foreach ($noReplacementIds as $id) {
            $verdicts[$id] = ['status' => self::STATUS_RESIDUAL, 'reason_code' => self::REASON_NO_REPLACEMENT_EXISTS];
        }

        foreach ($mismatchIds as $id) {
            $verdicts[$id] = ['status' => self::STATUS_RESIDUAL, 'reason_code' => self::REASON_ORPHAN_CONTENT_MISMATCH];
        }

        return [
            'day' => $dayKey,
            'passed' => $passed,
            'halt_reason' => $haltReason,
            'halt_message' => $haltMessage,
            'precondition' => ['passed' => true, 'pending_count' => 0],
            'totals' => [
                'orphans' => $totalOrphans,
                'inserted' => $totalInserted,
                'reconciled' => count($reconciledIds),
                'timestamp_out_of_tolerance' => count($toleranceIds),
                'no_replacement_exists' => count($noReplacementIds),
                'orphan_content_mismatch' => count($mismatchIds),
            ],
            'content_gap' => $contentGapInfo,
            'verdicts' => $verdicts,
        ];
    }

    protected function preconditionHaltResult(string $dayKey, int $pendingCount): array
    {
        return [
            'day' => $dayKey,
            'passed' => false,
            'halt_reason' => 'precondition_pending',
            'halt_message' => "Day {$dayKey} cannot be reconciled: {$pendingCount} transaction(s) on this day still have no terminal tax_backfill_records outcome.",
            'precondition' => ['passed' => false, 'pending_count' => $pendingCount],
            'totals' => [
                'orphans' => 0,
                'inserted' => 0,
                'reconciled' => 0,
                'timestamp_out_of_tolerance' => 0,
                'no_replacement_exists' => 0,
                'orphan_content_mismatch' => 0,
            ],
            'content_gap' => [
                'actual' => 0,
                'expected' => null,
                'ratio' => null,
                'missing_payload_count' => null,
                'total_affected_transactions' => null,
            ],
            'verdicts' => [],
        ];
    }

    /**
     * Step 2's precondition guard: day-D transactions with no
     * tax_backfill_records row at all yet (the operational meaning of
     * "pending" — see this class's own docblock and evaluate()'s comment).
     * Deliberately whole-day, all-tenants (never a tenant-scoped subset),
     * per the brief.
     */
    protected function countPendingTransactions(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table('transactions as t')
            ->where('t.created_at', '>=', $dayStart)
            ->where('t.created_at', '<', $dayEnd)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('tax_backfill_records as r')
                    ->whereColumn('r.transaction_pk', 't.id');
            })
            ->count();
    }

    /**
     * Step 5's `missing_payload_count(D)`: day-D transactions quarantined
     * with reason `missing_payload` (T013). Counted by distinct
     * transaction_pk — a transaction can carry more than one
     * tax_backfill_records row across separate runs (e.g. an earlier
     * dry-run plus the terminal apply run), and this must not double-count
     * the same transaction.
     */
    protected function countMissingPayload(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table('tax_backfill_records as r')
            ->join('transactions as t', 't.id', '=', 'r.transaction_pk')
            ->where('t.created_at', '>=', $dayStart)
            ->where('t.created_at', '<', $dayEnd)
            ->where('r.outcome', TaxBackfillOutcome::Quarantined->value)
            ->where('r.reason_code', TaxBackfillReasonCode::MissingPayload->value)
            ->distinct()
            ->count('r.transaction_pk');
    }

    /**
     * Step 5's `total_affected_transactions(D)`: day-D transactions that had
     * zero linked tax rows before this backfill began — the original defect
     * population for that day (research.md V4). `had_linked_rows_before`
     * (T012) is exactly this: false means "the transaction had no linked
     * transaction_taxes row when this run scanned it." Counted by distinct
     * transaction_pk for the same double-counting reason as
     * countMissingPayload() above.
     */
    protected function countAffectedTransactions(Carbon $dayStart, Carbon $dayEnd): int
    {
        return DB::table('tax_backfill_records as r')
            ->join('transactions as t', 't.id', '=', 'r.transaction_pk')
            ->where('t.created_at', '>=', $dayStart)
            ->where('t.created_at', '<', $dayEnd)
            ->where('r.had_linked_rows_before', false)
            ->distinct()
            ->count('r.transaction_pk');
    }

    /**
     * Step 1's orphan population: `transaction_taxes` rows with
     * `transaction_pk IS NULL` whose own `created_at` falls in
     * `[dayStart, dayEnd)` — the identical strict range used by
     * loadInserted(), no buffer (corrected 2026-08-12, Code Reviewer
     * finding — see evaluate()'s comment and
     * slice-13-orphan-reconcile-brief.md Step 1). Reads only
     * `transaction_taxes`, never joined to `transactions` — this is the
     * method the no-per-row-attribution structural test inspects.
     *
     * @return array<int, array{id: int, tax_type: string, amount: string, ts: Carbon}>
     */
    protected function loadOrphans(Carbon $dayStart, Carbon $dayEnd): array
    {
        return DB::table('transaction_taxes')
            ->whereNull('transaction_pk')
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'tax_type', 'amount', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'tax_type' => (string) $row->tax_type,
                'amount' => (string) $row->amount,
                'ts' => Carbon::parse($row->created_at),
            ])
            ->all();
    }

    /**
     * Step 1's inserted/reconstructed population: `transaction_taxes` rows
     * with `transaction_pk IS NOT NULL` whose own `created_at` (= the
     * parent transaction's `created_at`, by T026 convention) falls in
     * `[dayStart, dayEnd)`. No buffer on the upper bound — see evaluate().
     * Reads only `transaction_taxes`; `id` is carried for parity with
     * loadOrphans() but is never persisted or attributed to anything —
     * only orphan ids ever get written back in persist().
     *
     * @return array<int, array{id: int, tax_type: string, amount: string, ts: Carbon}>
     */
    protected function loadInserted(Carbon $dayStart, Carbon $dayEnd): array
    {
        return DB::table('transaction_taxes')
            ->whereNotNull('transaction_pk')
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'tax_type', 'amount', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'tax_type' => (string) $row->tax_type,
                'amount' => (string) $row->amount,
                'ts' => Carbon::parse($row->created_at),
            ])
            ->all();
    }

    /**
     * Groups already-sorted (by created_at, id) rows by `"{tax_type}|{amount}"`,
     * preserving each bucket's ascending order — required by matchBucket()'s
     * two-pointer algorithm.
     *
     * @param  array<int, array{id: int, tax_type: string, amount: string, ts: Carbon}>  $rows
     * @return array<string, array<int, array{id: int, tax_type: string, amount: string, ts: Carbon}>>
     */
    protected function bucketRows(array $rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row['tax_type'].'|'.$row['amount']][] = $row;
        }

        return $buckets;
    }

    /**
     * Step 3's greedy two-pointer tolerance match for a single
     * (`tax_type`, `amount`) bucket. $orphans and $inserts must already be
     * sorted ascending by `ts` (guaranteed by loadOrphans()/loadInserted()'s
     * ORDER BY plus bucketRows()'s order-preserving grouping).
     *
     * For each orphan in ascending order, permanently discards any
     * remaining inserted timestamp older than `orphan_ts - TOLERANCE` (it
     * can never satisfy this or any later orphan, since later orphans only
     * push the window further forward — see the brief's own optimality
     * argument), then claims the next remaining inserted timestamp if it is
     * not later than the orphan itself. This is a pure function over bare
     * `['id' => int, 'ts' => Carbon]` arrays: it never reads or returns
     * anything about `transactions`, and its return value never pairs a
     * matched orphan id with the specific inserted row/timestamp it
     * consumed — only a flat list of matched orphan ids, which is all Step 6
     * ever needs to write.
     *
     * @param  array<int, array{id: int, ts: Carbon}>  $orphans
     * @param  array<int, array{id: int, ts: Carbon}>  $inserts
     * @return array{matched_orphan_ids: array<int>, unmatched_orphans: array<int, array{id: int, ts: Carbon}>, insert_count: int}
     */
    protected function matchBucket(array $orphans, array $inserts): array
    {
        $matchedOrphanIds = [];
        $unmatchedOrphans = [];

        $insertIndex = 0;
        $insertCount = count($inserts);

        foreach ($orphans as $orphan) {
            $orphanTs = $orphan['ts'];
            $lowerBound = $orphanTs->copy()->subSeconds(self::TOLERANCE_SECONDS);

            while ($insertIndex < $insertCount && $inserts[$insertIndex]['ts']->lt($lowerBound)) {
                $insertIndex++;
            }

            if ($insertIndex < $insertCount && ! $inserts[$insertIndex]['ts']->gt($orphanTs)) {
                $matchedOrphanIds[] = $orphan['id'];
                $insertIndex++;
            } else {
                $unmatchedOrphans[] = $orphan;
            }
        }

        return [
            'matched_orphan_ids' => $matchedOrphanIds,
            'unmatched_orphans' => $unmatchedOrphans,
            'insert_count' => $insertCount,
        ];
    }
}
