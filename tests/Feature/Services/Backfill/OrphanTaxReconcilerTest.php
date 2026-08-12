<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Transaction;
use App\Services\Backfill\OrphanTaxReconciler;
use App\Services\Backfill\TaxBackfillOutcome;
use App\Services\Backfill\TaxBackfillReasonCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\DeadlockOnceOnMatchStatement;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 13 (T069, T071-partial) — Stage 2 of
 * 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-13-orphan-reconcile-brief.md.
 *
 * Covers OrphanTaxReconciler::evaluate()/persist() directly: the four
 * classification outcomes (reconciled / timestamp_out_of_tolerance /
 * no_replacement_exists / orphan_content_mismatch), the bounded
 * over-classification guard, the precondition guard, all-or-nothing
 * persistence, and the no-per-row-attribution structural guarantee.
 *
 * Every scenario below uses a distinct, fixed FUTURE calendar day
 * (2027-04-0N / 2028-01-01) rather than `now()` or a 2026 date. This
 * feature's evaluate() deliberately queries the WHOLE day across ALL
 * tenants (never scoped to this test's own fixture ids, per the brief) —
 * so, given the known RefreshDatabase isolation gap (tests/TestCase.php:38,
 * rows leak between tests), a shared/recent day would risk picking up
 * unrelated leaked fixtures. Every other date already used elsewhere in
 * this feature's tests is 2026 or `now()` (today is 2026-08-12); a
 * dedicated 2027/2028 day per test method is effectively collision-free.
 */
class OrphanTaxReconcilerTest extends TestCase
{
    private function reconciler(): OrphanTaxReconciler
    {
        return new OrphanTaxReconciler;
    }

    private function insertOrphan(Carbon $ts, string $taxType = 'VAT', string $amount = '10.00'): int
    {
        return DB::table('transaction_taxes')->insertGetId([
            'transaction_pk' => null,
            'tax_type' => $taxType,
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    private function insertReconstructed(int $transactionPk, Carbon $ts, string $taxType = 'VAT', string $amount = '10.00'): int
    {
        return DB::table('transaction_taxes')->insertGetId([
            'transaction_pk' => $transactionPk,
            'tax_type' => $taxType,
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    private function insertArchiveRow(int $originalId): void
    {
        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $originalId,
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
            'archive_run_id' => 'test-fixture',
            'archived_at' => now(),
            'reconciled_status' => null,
            'reason_code' => null,
        ]);
    }

    private function archiveRowFor(int $originalId): ?object
    {
        return DB::table('transaction_taxes_orphan_archive')->where('original_id', $originalId)->first();
    }

    /** A day-D transaction with a terminal (had_linked_rows_before/outcome) tax_backfill_records row. */
    private function makeAffectedTransaction(Carbon $day, string $outcome, ?string $reasonCode, bool $hadLinkedRowsBefore): Transaction
    {
        $transaction = Transaction::factory()->create(['created_at' => $day->copy()->addHours(random_int(0, 20))]);

        $run = TaxBackfillRun::create([
            'window_start' => $day,
            'window_end' => $day->copy()->endOfDay(),
            'mode' => TaxBackfillRun::MODE_APPLY,
            'scanned_count' => 1,
            'reconstructed_count' => 0,
            'skipped_existing_count' => 0,
            'quarantined_count' => 0,
            'failed_count' => 0,
            'status' => TaxBackfillRun::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        TaxBackfillRecord::create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $transaction->tenant_id,
            'reconstructed_tax_rows' => [],
            'had_linked_rows_before' => $hadLinkedRowsBefore,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
        ]);

        return $transaction;
    }

    public function test_pure_reconciled_day_matches_every_orphan_within_tolerance(): void
    {
        $day = Carbon::parse('2027-04-01 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $orphan1 = $this->insertOrphan($day->copy()->addSeconds(5));
        $orphan2 = $this->insertOrphan($day->copy()->addSeconds(50));
        $this->insertReconstructed($dummyTransactionId, $day->copy()->addSeconds(0));
        $this->insertReconstructed($dummyTransactionId, $day->copy()->addSeconds(45));

        $this->insertArchiveRow($orphan1);
        $this->insertArchiveRow($orphan2);

        $result = $this->reconciler()->evaluate($day);

        $this->assertTrue($result['passed']);
        $this->assertNull($result['halt_reason']);
        $this->assertSame(2, $result['totals']['orphans']);
        $this->assertSame(2, $result['totals']['inserted']);
        $this->assertSame(2, $result['totals']['reconciled']);
        $this->assertSame(0, $result['totals']['timestamp_out_of_tolerance']);
        $this->assertSame(0, $result['totals']['no_replacement_exists']);
        $this->assertSame(0, $result['totals']['orphan_content_mismatch']);
        $this->assertSame(0, $result['content_gap']['actual']);

        $this->assertSame(['status' => 'reconciled', 'reason_code' => null], $result['verdicts'][$orphan1]);
        $this->assertSame(['status' => 'reconciled', 'reason_code' => null], $result['verdicts'][$orphan2]);

        $this->reconciler()->persist($result);

        $archived1 = $this->archiveRowFor($orphan1);
        $archived2 = $this->archiveRowFor($orphan2);

        $this->assertSame('reconciled', $archived1->reconciled_status);
        $this->assertNull($archived1->reason_code);
        $this->assertSame('reconciled', $archived2->reconciled_status);
        $this->assertNull($archived2->reason_code);
    }

    /**
     * Regression test for the exact over-classification risk flagged in
     * architect review (brief, Step 4): a bucket with far more unpaired
     * orphans than unpaired inserted rows must classify at most
     * min(unpaired_orphan_count, unpaired_insert_count) as
     * `timestamp_out_of_tolerance` — never every unpaired orphan.
     *
     * No day-D `transactions`/`tax_backfill_records` fixtures are created
     * here deliberately: this test is scoped to Steps 3-4's bucket
     * arithmetic alone (zero matches within tolerance, by construction —
     * every inserted timestamp is placed ~2000s after every orphan
     * timestamp), not Step 5's day-level no_replacement_exists vs.
     * orphan_content_mismatch verdict. With no affected-transaction
     * population for this day, Step 5 fails closed (content_gap.ratio is
     * undefined) and the day halts as `orphan_content_mismatch` — the
     * assertions below only check the bucket-level counts this test cares
     * about, not the day's overall pass/fail.
     */
    public function test_timestamp_out_of_tolerance_is_bounded_by_the_smaller_side(): void
    {
        $day = Carbon::parse('2027-04-02 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $orphanRows = [];
        for ($i = 0; $i < 1000; $i++) {
            $ts = $day->copy()->addSeconds($i);
            $orphanRows[] = [
                'transaction_pk' => null,
                'tax_type' => 'VAT',
                'amount' => '10.00',
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }
        DB::table('transaction_taxes')->insert($orphanRows);

        $insertedRows = [];
        for ($i = 0; $i < 950; $i++) {
            $ts = $day->copy()->addSeconds(2000 + $i);
            $insertedRows[] = [
                'transaction_pk' => $dummyTransactionId,
                'tax_type' => 'VAT',
                'amount' => '10.00',
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }
        DB::table('transaction_taxes')->insert($insertedRows);

        $result = $this->reconciler()->evaluate($day);

        $this->assertSame(1000, $result['totals']['orphans']);
        $this->assertSame(950, $result['totals']['inserted']);
        $this->assertSame(0, $result['totals']['reconciled']);
        $this->assertSame(950, $result['totals']['timestamp_out_of_tolerance'], 'Must be bounded by the insert side (950), never the orphan side (1000).');
        $this->assertNotSame(1000, $result['totals']['timestamp_out_of_tolerance']);
        $this->assertSame(50, $result['totals']['no_replacement_exists'] + $result['totals']['orphan_content_mismatch']);

        // Pin the fail-closed branch this test incidentally exercises
        // (zero total_affected_transactions, since no
        // tax_backfill_records fixtures exist for this day) rather than
        // just executing it without asserting on it (Code Reviewer
        // finding, Slice 13 second review pass).
        $this->assertFalse($result['passed']);
        $this->assertSame('orphan_content_mismatch', $result['halt_reason']);
        $this->assertNull($result['content_gap']['ratio']);
        $this->assertStringContainsString('no affected-transaction population', $result['halt_message']);
    }

    public function test_content_gap_matching_expected_ratio_classifies_as_no_replacement_exists_and_does_not_halt(): void
    {
        $day = Carbon::parse('2027-04-03 00:00:00');

        $orphanIds = [];
        for ($i = 0; $i < 4; $i++) {
            $orphanIds[] = $this->insertOrphan($day->copy()->addMinutes($i), 'VAT', '20.00');
            $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        }

        $result = $this->reconciler()->evaluate($day);

        $this->assertTrue($result['passed']);
        $this->assertNull($result['halt_reason']);
        $this->assertSame(0, $result['totals']['reconciled']);
        $this->assertSame(0, $result['totals']['timestamp_out_of_tolerance']);
        $this->assertSame(4, $result['totals']['no_replacement_exists']);
        $this->assertSame(0, $result['totals']['orphan_content_mismatch']);
        $this->assertSame(4, $result['content_gap']['actual']);
        $this->assertSame(4, $result['content_gap']['expected']);
        $this->assertSame(1, $result['content_gap']['ratio']);
        $this->assertSame(4, $result['content_gap']['missing_payload_count']);
        $this->assertSame(4, $result['content_gap']['total_affected_transactions']);

        foreach ($orphanIds as $id) {
            $this->assertSame(['status' => 'residual', 'reason_code' => 'no_replacement_exists'], $result['verdicts'][$id]);
        }
    }

    public function test_content_gap_mismatching_expected_ratio_halts_as_orphan_content_mismatch_and_persists_nothing(): void
    {
        $day = Carbon::parse('2027-04-04 00:00:00');

        $orphanIds = [];
        for ($i = 0; $i < 4; $i++) {
            $orphanIds[] = $this->insertOrphan($day->copy()->addMinutes($i), 'VAT', '30.00');
            $this->insertArchiveRow($orphanIds[$i]);
        }

        // 3 of the 4 affected transactions are missing_payload; the 4th is
        // affected (had_linked_rows_before = false) but applied cleanly —
        // so missing_payload_count(D)=3 while total_affected_transactions(D)=4,
        // giving expected_content_gap = 3*1 = 3, which does not match this
        // day's actual_content_gap of 4.
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Applied->value, null, false);

        $result = $this->reconciler()->evaluate($day);

        $this->assertFalse($result['passed']);
        $this->assertSame('orphan_content_mismatch', $result['halt_reason']);
        $this->assertSame(4, $result['content_gap']['actual']);
        $this->assertSame(3, $result['content_gap']['expected']);
        $this->assertSame(1, $result['content_gap']['ratio']);
        $this->assertSame(0, $result['totals']['no_replacement_exists']);
        $this->assertSame(4, $result['totals']['orphan_content_mismatch']);

        try {
            $this->reconciler()->persist($result);
            $this->fail('persist() must refuse to write a halted day.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not pass', $e->getMessage());
        }

        foreach ($orphanIds as $id) {
            $archived = $this->archiveRowFor($id);
            $this->assertNull($archived->reconciled_status, 'A halted day must leave every archive row untouched.');
            $this->assertNull($archived->reason_code);
        }
    }

    public function test_non_integral_ratio_fails_closed_and_halts(): void
    {
        $day = Carbon::parse('2027-04-05 00:00:00');

        for ($i = 0; $i < 4; $i++) {
            $this->insertOrphan($day->copy()->addMinutes($i), 'VAT', '40.00');
        }

        // 3 affected transactions, 4 orphans -> ratio(D) = 4/3, non-integral.
        for ($i = 0; $i < 3; $i++) {
            $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        }

        $result = $this->reconciler()->evaluate($day);

        $this->assertFalse($result['passed']);
        $this->assertSame('orphan_content_mismatch', $result['halt_reason']);
        $this->assertNull($result['content_gap']['ratio']);
        $this->assertNull($result['content_gap']['expected']);
        $this->assertSame(4, $result['content_gap']['actual']);
        $this->assertStringContainsString('non-integral', $result['halt_message']);
    }

    /**
     * Regression test for Finding #1 of Slice 13's second Code Reviewer
     * pass: the day-boundary double-processing/clobbering bug. The
     * (now-removed) +10s upper-bound buffer on the orphan query would have
     * let an orphan created just after midnight on day D+1 be loaded and
     * given a verdict by BOTH day D's evaluate() (via the buffer) and day
     * D+1's own evaluate(), so that whichever day's --apply ran second
     * would silently clobber the other's persisted verdict for that row.
     *
     * Per the corrected design (brief Step 1), day D's and day D+1's
     * candidate queries must be provably disjoint: an orphan stamped
     * `D+1 00:00:03` must never appear in day D's verdicts, only in day
     * D+1's.
     */
    public function test_orphan_near_midnight_is_never_given_a_verdict_by_both_adjacent_days(): void
    {
        $dayD = Carbon::parse('2027-04-07 00:00:00');
        $dayD1 = Carbon::parse('2027-04-08 00:00:00');

        $orphanId = $this->insertOrphan($dayD1->copy()->addSeconds(3), 'VAT', '70.00');

        $resultD = $this->reconciler()->evaluate($dayD);
        $resultD1 = $this->reconciler()->evaluate($dayD1);

        $this->assertArrayNotHasKey(
            $orphanId,
            $resultD['verdicts'],
            "Day D's evaluate() must never load (or give a verdict to) an orphan whose created_at falls on day D+1."
        );
        $this->assertArrayHasKey(
            $orphanId,
            $resultD1['verdicts'],
            "Day D+1's evaluate() must load an orphan created within D+1's own [D+1 00:00:00, D+2 00:00:00) range."
        );
        $this->assertSame(0, $resultD['totals']['orphans']);
    }

    public function test_precondition_guard_refuses_when_a_transaction_has_no_terminal_outcome_yet(): void
    {
        $day = Carbon::parse('2027-04-06 00:00:00');

        // Two day-D transactions already fully processed...
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Applied->value, null, false);
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Applied->value, null, false);

        // ...but a third has no tax_backfill_records row at all yet (e.g.
        // apply() was invoked tenant-scoped and hasn't reached this
        // transaction's tenant).
        Transaction::factory()->create(['created_at' => $day->copy()->addHours(5)]);

        $orphanId = $this->insertOrphan($day->copy()->addMinutes(1), 'VAT', '50.00');
        $this->insertArchiveRow($orphanId);

        $result = $this->reconciler()->evaluate($day);

        $this->assertFalse($result['passed']);
        $this->assertSame('precondition_pending', $result['halt_reason']);
        $this->assertSame(1, $result['precondition']['pending_count']);
        $this->assertSame([], $result['verdicts'], 'The precondition guard must short-circuit before any orphan is even loaded, let alone classified.');
        $this->assertSame(0, $result['totals']['orphans']);

        try {
            $this->reconciler()->persist($result);
            $this->fail('persist() must refuse to write a precondition-refused day.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not pass', $e->getMessage());
        }

        $archived = $this->archiveRowFor($orphanId);
        $this->assertNull($archived->reconciled_status);
        $this->assertNull($archived->reason_code);
    }

    public function test_match_bucket_returns_only_flat_orphan_id_lists_with_no_transaction_linkage(): void
    {
        $method = new ReflectionMethod(OrphanTaxReconciler::class, 'matchBucket');
        $method->setAccessible(true);

        $orphans = [
            ['id' => 101, 'ts' => Carbon::parse('2028-01-01 00:00:05')],
            ['id' => 102, 'ts' => Carbon::parse('2028-01-01 00:01:00')],
        ];
        $inserts = [
            ['id' => 555, 'ts' => Carbon::parse('2028-01-01 00:00:00')],
        ];

        $result = $method->invoke($this->reconciler(), $orphans, $inserts);

        $this->assertSame([101], $result['matched_orphan_ids']);
        $this->assertSame(1, $result['insert_count']);
        $this->assertCount(1, $result['unmatched_orphans']);
        $this->assertSame(102, $result['unmatched_orphans'][0]['id']);

        // The matched-orphan-ids list is a flat array of orphan ids only --
        // no companion insert/transaction identifier is ever attached to a
        // match, which is what would constitute "this orphan belongs to
        // that transaction" attribution (research.md V4, explicitly
        // rejected by this feature).
        foreach ($result['matched_orphan_ids'] as $matchedId) {
            $this->assertIsInt($matchedId);
        }
    }

    public function test_orphan_and_bucket_matching_never_join_to_the_transactions_table(): void
    {
        $source = file_get_contents(app_path('Services/Backfill/OrphanTaxReconciler.php'));
        $this->assertNotFalse($source);

        $loadOrphansBody = $this->extractMethodBody($source, 'loadOrphans');
        $matchBucketBody = $this->extractMethodBody($source, 'matchBucket');

        $this->assertStringNotContainsStringIgnoringCase(
            'transactions',
            $loadOrphansBody,
            "loadOrphans() must read only transaction_taxes -- joining to transactions here would be the per-row attribution this feature's research.md V4 explicitly rejects."
        );

        $this->assertStringNotContainsStringIgnoringCase(
            'transactions',
            $matchBucketBody,
            'matchBucket() must operate purely on (id, timestamp) pairs -- it must never reference the transactions table.'
        );
    }

    /**
     * 002-backfill-transaction-taxes, Slice 17 (T100) — operational
     * watchdog controls. See
     * specs/002-backfill-transaction-taxes/slice-17-operational-watchdog-brief.md.
     * Rewritten during Slice 17 review after the original version of this
     * test (which used a single group and only asserted that group's final
     * state) was found not to actually prove correctness: persist() no
     * longer wraps each chunk's UPDATE in DeadlockRetryService (that
     * approach had a silent-partial-write bug on a genuine deadlock — see
     * persist()'s own docblock) and instead relies entirely on Laravel's
     * native `DB::transaction($callback, 5)` retry, which rolls back and
     * re-runs the WHOLE closure — every group's UPDATE, not just the one
     * that failed — on every retry.
     *
     * This test uses two groups ("reconciled" and "no_replacement_exists",
     * mirroring the fixture shape of
     * test_persist_still_rolls_back_the_whole_day_when_a_later_group_fails_after_an_earlier_group_already_succeeded()
     * below) and forces a genuine deadlock message on the SECOND group's
     * UPDATE, on its first attempt only, via the shared
     * Tests\Support\DeadlockOnceOnMatchStatement double.
     *
     * Final-state assertions on both groups (that every row ended up with
     * the correct reconciled_status/reason_code) are necessary but NOT
     * sufficient to distinguish the fixed version from the original bug:
     * DeadlockOnceOnMatchStatement only throws a client-side PDOException,
     * it never performs the real server-side transaction abort a genuine
     * MySQL deadlock would — so under the OLD per-chunk-retry code, the
     * first group's already-applied UPDATE was never actually undone
     * either (nothing in that code path ever called rollBack()), and the
     * final state came out looking correct there too. The assertion that
     * actually discriminates the two implementations is the execution
     * COUNT below: DB::listen() only fires for statements that complete
     * without throwing (Illuminate\Database\Connection::run() dispatches
     * 'queryExecuted' only after a successful return), so counting real
     * completions of the FIRST group's UPDATE proves whether the whole
     * closure was re-run (count = 2, this fix) or only the failing chunk
     * was retried in place (count = 1, the original bug — confirmed by
     * temporarily reverting to the old $wrapInTransaction = false approach
     * and re-running this test, which fails on that assertion; see this
     * slice's review notes).
     *
     * Each group's UPDATE is targeted by its compiled placeholder count
     * (2 SET + N `whereIn` ids), NOT by SQL text alone — both groups'
     * statements are textually identical apart from the number of `?`
     * placeholders in the `IN (...)` clause, and DeadlockOnceOnMatchStatement's
     * matcher only ever sees raw SQL text, never bound values. Group A
     * (1 id) compiles to 3 placeholders total; Group B (4 ids) compiles to
     * 6 — distinct and deterministic given these fixed fixture sizes.
     *
     * Needs the same DB::commit()-before/DB::beginTransaction()-in-finally
     * workaround as TaxBackfillRunnerTest's own equivalent regression test
     * (see that test's docblock for the full rationale): persist()'s
     * enclosing DB::transaction() must run at REAL depth 1 for Laravel's
     * retry to engage at all — at depth > 1, ManagesTransactions::
     * handleTransactionException() treats a concurrency error as
     * unrecoverable and throws immediately instead of retrying, which is
     * exactly the nested-transaction failure mode documented elsewhere in
     * this codebase (TaxBackfillRunner::processTransactionForApply()) and
     * would happen here too if this test ran directly inside
     * RefreshDatabase's own ambient wrapping transaction.
     */
    public function test_persist_retries_the_whole_transaction_from_scratch_when_a_later_group_hits_a_genuine_deadlock_and_every_group_ends_up_correctly_updated(): void
    {
        DB::commit();

        $companyIdsBefore = DB::table('companies')->pluck('id')->all();
        $tenantIdsBefore = DB::table('tenants')->pluck('id')->all();
        $terminalIdsBefore = DB::table('pos_terminals')->pluck('id')->all();
        $transactionIdsBefore = DB::table('transactions')->pluck('id')->all();
        $runIdsBefore = DB::table('tax_backfill_runs')->pluck('id')->all();

        $pdo = DB::connection()->getPdo();
        $defaultStatementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);

        $day = Carbon::parse('2027-04-11 00:00:00');

        try {
            $dummyTransactionId = Transaction::factory()->create()->id;

            // Group A ("reconciled"): a single matched orphan. persist()
            // iterates $groups in the order buildResult() populates
            // $verdicts (reconciledIds appended before noReplacementIds),
            // so this is the FIRST group's UPDATE.
            $reconciledOrphan = $this->insertOrphan($day->copy()->addSeconds(5), 'VAT', '81.00');
            $this->insertReconstructed($dummyTransactionId, $day->copy()->addSeconds(0), 'VAT', '81.00');
            $this->insertArchiveRow($reconciledOrphan);

            // Group B ("no_replacement_exists"): 4 unmatched orphans in a
            // distinct bucket, sized so Step 5's cross-check passes
            // (ratio(D) = 5/5 = 1, expected_content_gap = 4*1 = 4 = actual).
            $noReplacementOrphans = [];
            for ($i = 0; $i < 4; $i++) {
                $id = $this->insertOrphan($day->copy()->addMinutes($i + 1), 'VAT', '82.00');
                $this->insertArchiveRow($id);
                $noReplacementOrphans[] = $id;
            }

            for ($i = 0; $i < 4; $i++) {
                $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
            }
            $this->makeAffectedTransaction($day, TaxBackfillOutcome::Applied->value, null, false);

            $result = $this->reconciler()->evaluate($day);
            $this->assertTrue($result['passed'], 'Sanity check: the day must genuinely pass evaluation for persist() to attempt writing both groups.');
            $this->assertSame(1, $result['totals']['reconciled']);
            $this->assertSame(4, $result['totals']['no_replacement_exists']);

            $groupAExecutions = 0;
            $groupBExecutions = 0;

            DB::listen(function ($query) use (&$groupAExecutions, &$groupBExecutions) {
                $sql = strtolower(trim($query->sql));

                if (! str_starts_with($sql, 'update') || ! str_contains($sql, 'transaction_taxes_orphan_archive')) {
                    return;
                }

                match (substr_count($query->sql, '?')) {
                    3 => $groupAExecutions++,
                    6 => $groupBExecutions++,
                    default => null,
                };
            });

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DeadlockOnceOnMatchStatement::class, []]);
            DeadlockOnceOnMatchStatement::$matcher = fn (string $sql) => str_starts_with(strtolower(trim($sql)), 'update')
                && str_contains($sql, 'transaction_taxes_orphan_archive')
                && substr_count($sql, '?') === 6;
            DeadlockOnceOnMatchStatement::$remainingFailures = 1;

            $this->reconciler()->persist($result);

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);

            $this->assertSame(
                1,
                DeadlockOnceOnMatchStatement::$timesTriggered,
                "The forced deadlock never actually triggered on group B's archive UPDATE — this test would otherwise pass vacuously."
            );

            // The discriminating assertion: group A's UPDATE must have run
            // TWICE (once in the attempt that failed on group B, once more
            // when Laravel's native retry rolled back and re-ran the whole
            // closure) — the original per-chunk DeadlockRetryService
            // approach would show this as 1, never touching group A again
            // after its first, already-successful chunk.
            $this->assertSame(2, $groupAExecutions, "Expected group A's UPDATE to have been re-executed by the whole-closure transaction retry.");
            $this->assertSame(1, $groupBExecutions, "Expected group B's UPDATE to show exactly one completed execution (its first attempt threw and is not counted; its retry succeeded).");

            $archivedReconciled = $this->archiveRowFor($reconciledOrphan);
            $this->assertSame('reconciled', $archivedReconciled->reconciled_status, 'Expected the retried second attempt to have actually persisted every group, not just the one that deadlocked.');
            $this->assertNull($archivedReconciled->reason_code);

            foreach ($noReplacementOrphans as $id) {
                $archived = $this->archiveRowFor($id);
                $this->assertSame('residual', $archived->reconciled_status);
                $this->assertSame('no_replacement_exists', $archived->reason_code);
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);
            DeadlockOnceOnMatchStatement::reset();

            DB::table('transaction_taxes_orphan_archive')
                ->where('created_at', '>=', $day)->where('created_at', '<', $day->copy()->addDay())
                ->delete();
            DB::table('transaction_taxes')
                ->where('created_at', '>=', $day)->where('created_at', '<', $day->copy()->addDay())
                ->delete();

            $newRunIds = DB::table('tax_backfill_runs')->whereNotIn('id', $runIdsBefore)->pluck('id');
            DB::table('tax_backfill_records')->whereIn('run_id', $newRunIds)->delete();
            DB::table('tax_backfill_runs')->whereIn('id', $newRunIds)->delete();
            DB::table('transactions')->whereNotIn('id', $transactionIdsBefore)->delete();

            DB::table('pos_terminals')->whereNotIn('id', $terminalIdsBefore)->delete();
            DB::table('tenants')->whereNotIn('id', $tenantIdsBefore)->delete();
            DB::table('companies')->whereNotIn('id', $companyIdsBefore)->delete();

            DB::beginTransaction();
        }
    }

    /**
     * Slice 17 (T100): persist()'s all-or-nothing-per-day guarantee must
     * survive the new retry wrapping unchanged. Uses persist()'s own
     * pre-existing affected-row-count RuntimeException (not a simulated DB
     * exception) to force a failure on the SECOND of two groups, after the
     * FIRST group's UPDATE has already run — proving the whole day's
     * persist() call, including the first group's otherwise-successful
     * write, rolls back together rather than leaving a partial write.
     */
    public function test_persist_still_rolls_back_the_whole_day_when_a_later_group_fails_after_an_earlier_group_already_succeeded(): void
    {
        $day = Carbon::parse('2027-04-12 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        // Group 1 ("reconciled"): a single matched orphan, processed first
        // (buildResult() appends reconciledIds before noReplacementIds into
        // $verdicts, and persist() groups/iterates in that same order).
        $reconciledOrphan = $this->insertOrphan($day->copy()->addSeconds(5), 'VAT', '55.00');
        $this->insertReconstructed($dummyTransactionId, $day->copy()->addSeconds(0), 'VAT', '55.00');
        $this->insertArchiveRow($reconciledOrphan);

        // Group 2 ("residual"/"no_replacement_exists"): 4 unmatched orphans
        // in a distinct bucket, sized so Step 5's cross-check passes
        // (ratio(D) = 5/5 = 1, expected_content_gap = 4*1 = 4 = actual).
        $noReplacementOrphans = [];
        for ($i = 0; $i < 4; $i++) {
            $id = $this->insertOrphan($day->copy()->addMinutes($i + 1), 'VAT', '66.00');
            $this->insertArchiveRow($id);
            $noReplacementOrphans[] = $id;
        }

        for ($i = 0; $i < 4; $i++) {
            $this->makeAffectedTransaction($day, TaxBackfillOutcome::Quarantined->value, TaxBackfillReasonCode::MissingPayload->value, false);
        }
        $this->makeAffectedTransaction($day, TaxBackfillOutcome::Applied->value, null, false);

        $result = $this->reconciler()->evaluate($day);

        $this->assertTrue($result['passed'], 'Sanity check: the day must genuinely pass evaluation for persist() to attempt writing both groups.');
        $this->assertSame(1, $result['totals']['reconciled']);
        $this->assertSame(4, $result['totals']['no_replacement_exists']);

        // Corrupt the SECOND group's archive population after evaluate() has
        // already computed its verdicts in memory, but before persist()
        // writes them: deleting one of the 4 no_replacement_exists archive
        // rows makes persist()'s own affected-row-count assertion for that
        // group's chunk fail ($affected !== count($chunk)) — a plain
        // RuntimeException, not a DB-level exception, so this test needs no
        // PDO trickery and is unaffected by transaction-nesting/deadlock-
        // conversion subtleties.
        DB::table('transaction_taxes_orphan_archive')->where('original_id', $noReplacementOrphans[0])->delete();

        try {
            $this->reconciler()->persist($result);
            $this->fail("Expected persist() to throw once the second group's affected-row count no longer matched.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refusing to report success on a partial write', $e->getMessage());
        }

        // The whole day's persist() call rolled back together — group 1's
        // otherwise-successful UPDATE must not have survived either.
        $archivedReconciled = $this->archiveRowFor($reconciledOrphan);
        $this->assertNull($archivedReconciled->reconciled_status, "Group 1's update must roll back along with group 2's failure — persist() is all-or-nothing per day.");
        $this->assertNull($archivedReconciled->reason_code);

        foreach (array_slice($noReplacementOrphans, 1) as $id) {
            $archived = $this->archiveRowFor($id);
            $this->assertNull($archived->reconciled_status);
        }
    }

    private function extractMethodBody(string $source, string $methodName): string
    {
        $pattern = '/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)[^{]*\{/';

        $this->assertMatchesRegularExpression($pattern, $source, "Could not locate method {$methodName}() in OrphanTaxReconciler.php.");

        preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
        $start = $matches[0][1] + strlen($matches[0][0]);

        $depth = 1;
        $i = $start;
        $length = strlen($source);

        while ($depth > 0 && $i < $length) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
            }
            $i++;
        }

        return substr($source, $start, $i - $start - 1);
    }
}
