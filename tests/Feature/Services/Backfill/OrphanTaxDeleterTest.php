<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Backfill;

use App\Models\Transaction;
use App\Services\Backfill\OrphanTaxDeleter;
use App\Services\DeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\DeadlockOnceOnMatchStatement;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 14 (T070, T070a, T071, T079) —
 * Stage 3 of 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-14-orphan-delete-brief.md.
 *
 * Covers OrphanTaxDeleter::preflight()/delete() directly: both
 * precondition-refusal cases, token-mismatch refusal, the happy-path
 * delete (with an explicit sibling/linked-row survival check), the
 * 2026-06-13-style uniform no_replacement_exists deletion, chunking (proven
 * via query-log inspection, not just row counts), idempotency, hash
 * determinism, and day-binding.
 *
 * Every scenario below uses a distinct, fixed FUTURE calendar day
 * (2027-06-0N) rather than `now()` or a 2026 date, matching
 * OrphanTaxReconcilerTest's own convention: OrphanTaxDeleter's precondition
 * checks deliberately reason over a WHOLE day across ALL tenants (never
 * scoped to a test's own fixture ids), so — given the known RefreshDatabase
 * isolation gap (tests/TestCase.php:38, rows leak between tests) — a
 * shared/recent day would risk picking up unrelated leaked fixtures. A
 * dedicated 2027 day per test method is effectively collision-free.
 */
class OrphanTaxDeleterTest extends TestCase
{
    private function deleter(?DeadlockRetryService $retryService = null): OrphanTaxDeleter
    {
        return new OrphanTaxDeleter($retryService ?? new DeadlockRetryService);
    }

    private function insertLiveOrphan(Carbon $ts, string $taxType = 'VAT', string $amount = '10.00'): int
    {
        return DB::table('transaction_taxes')->insertGetId([
            'transaction_pk' => null,
            'tax_type' => $taxType,
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    private function insertLinkedRow(int $transactionPk, Carbon $ts, string $taxType = 'VAT', string $amount = '10.00'): int
    {
        return DB::table('transaction_taxes')->insertGetId([
            'transaction_pk' => $transactionPk,
            'tax_type' => $taxType,
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    private function insertArchiveRow(
        int $originalId,
        Carbon $createdAt,
        ?string $reconciledStatus,
        ?string $reasonCode = null,
        string $taxType = 'VAT',
        string $amount = '10.00'
    ): void {
        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $originalId,
            'transaction_pk' => null,
            'tax_type' => $taxType,
            'amount' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'archive_run_id' => 'test-fixture',
            'archived_at' => now(),
            'reconciled_status' => $reconciledStatus,
            'reason_code' => $reasonCode,
        ]);
    }

    /**
     * Seeds a fully archived-and-reconciled day: $count live orphans, each
     * with a matching archive row carrying the given verdict. Returns the
     * live orphan ids, ascending.
     *
     * @return array<int>
     */
    private function seedReconciledDay(Carbon $day, int $count, string $status = 'reconciled', ?string $reasonCode = null): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ts = $day->copy()->addMinutes($i);
            $id = $this->insertLiveOrphan($ts, 'VAT', '10.00');
            $this->insertArchiveRow($id, $ts, $status, $reasonCode, 'VAT', '10.00');
            $ids[] = $id;
        }

        return $ids;
    }

    // -- Precondition 1: archive incomplete --------------------------------

    public function test_preflight_refuses_when_a_live_orphan_has_no_archive_row(): void
    {
        $day = Carbon::parse('2027-06-01 00:00:00');

        $this->insertLiveOrphan($day->copy()->addMinutes(1));

        $result = $this->deleter()->preflight($day);

        $this->assertFalse($result['passed']);
        $this->assertSame('archive_incomplete', $result['refusal_reason']);
        $this->assertNull($result['authorization_token']);
        $this->assertNull($result['preview_count']);
        $this->assertFalse($result['preconditions']['archive_complete']);
        $this->assertSame(1, $result['preconditions']['missing_archive_count']);
    }

    public function test_delete_performs_zero_deletes_when_archive_is_incomplete(): void
    {
        $day = Carbon::parse('2027-06-01 00:00:00');

        $orphanId = $this->insertLiveOrphan($day->copy()->addMinutes(1));

        $watermark = (int) DB::table('transaction_taxes')->count();

        $result = $this->deleter()->delete($day, 'any-token-at-all', 1000);

        $this->assertFalse($result['authorized']);
        $this->assertSame('archive_incomplete', $result['refusal_reason']);
        $this->assertSame(0, $result['chunks_processed']);
        $this->assertSame(0, $result['rows_deleted']);
        $this->assertNull($result['token_verified']);

        $this->assertSame($watermark, (int) DB::table('transaction_taxes')->count());
        $this->assertNotNull(DB::table('transaction_taxes')->where('id', $orphanId)->first(), 'The live orphan must survive an archive-incomplete refusal.');
    }

    // -- Precondition 2: reconciliation not passed --------------------------

    public function test_preflight_refuses_when_an_archived_row_has_no_reconciled_status(): void
    {
        $day = Carbon::parse('2027-06-02 00:00:00');

        $id = $this->insertLiveOrphan($day->copy()->addMinutes(1));
        $this->insertArchiveRow($id, $day->copy()->addMinutes(1), null);

        $result = $this->deleter()->preflight($day);

        $this->assertFalse($result['passed']);
        $this->assertSame('reconciliation_not_passed', $result['refusal_reason']);
        $this->assertNull($result['authorization_token']);
        $this->assertNull($result['preview_count']);
        $this->assertTrue($result['preconditions']['archive_complete']);
        $this->assertFalse($result['preconditions']['reconciliation_passed']);
        $this->assertSame(1, $result['preconditions']['unreconciled_count']);
    }

    public function test_delete_performs_zero_deletes_when_reconciliation_has_not_passed(): void
    {
        $day = Carbon::parse('2027-06-02 00:00:00');

        $id = $this->insertLiveOrphan($day->copy()->addMinutes(1));
        $this->insertArchiveRow($id, $day->copy()->addMinutes(1), null);

        $result = $this->deleter()->delete($day, 'any-token-at-all', 1000);

        $this->assertFalse($result['authorized']);
        $this->assertSame('reconciliation_not_passed', $result['refusal_reason']);
        $this->assertSame(0, $result['rows_deleted']);
        $this->assertNull($result['token_verified']);

        $this->assertNotNull(DB::table('transaction_taxes')->where('id', $id)->first());
        $archived = DB::table('transaction_taxes_orphan_archive')->where('original_id', $id)->first();
        $this->assertNull($archived->reconciled_status, 'The archive row must be left exactly as found.');
    }

    // -- Token mismatch -------------------------------------------------------

    public function test_delete_refuses_on_token_mismatch_verified_against_the_database(): void
    {
        $day = Carbon::parse('2027-06-03 00:00:00');
        $ids = $this->seedReconciledDay($day, 3);

        $result = $this->deleter()->delete($day, 'definitely-the-wrong-token', 1000);

        $this->assertFalse($result['authorized']);
        $this->assertSame('token_mismatch', $result['refusal_reason']);
        $this->assertFalse($result['token_verified']);
        $this->assertSame(0, $result['rows_deleted']);
        $this->assertSame(0, $result['chunks_processed']);

        foreach ($ids as $id) {
            $this->assertNotNull(
                DB::table('transaction_taxes')->where('id', $id)->whereNull('transaction_pk')->first(),
                "Orphan {$id} must survive a token-mismatch refusal."
            );
        }
    }

    // -- Token match: happy path ---------------------------------------------

    public function test_happy_path_deletes_every_archived_reconciled_orphan_and_leaves_the_archive_and_other_days_untouched(): void
    {
        $day = Carbon::parse('2027-06-04 00:00:00');
        $adjacentDay = Carbon::parse('2027-06-05 00:00:00');

        $ids = $this->seedReconciledDay($day, 3);
        $adjacentIds = $this->seedReconciledDay($adjacentDay, 2);

        $preflight = $this->deleter()->preflight($day);
        $this->assertTrue($preflight['passed']);
        $this->assertSame(3, $preflight['preview_count']);

        $result = $this->deleter()->delete($day, $preflight['authorization_token'], 1000);

        $this->assertTrue($result['authorized']);
        $this->assertTrue($result['token_verified']);
        $this->assertSame(1, $result['chunks_processed']);
        $this->assertSame(3, $result['rows_deleted']);
        $this->assertSame(0, $result['already_deleted']);

        foreach ($ids as $id) {
            $this->assertNull(
                DB::table('transaction_taxes')->where('id', $id)->first(),
                "Orphan {$id} must be deleted from the live table."
            );

            $archived = DB::table('transaction_taxes_orphan_archive')->where('original_id', $id)->first();
            $this->assertNotNull($archived, 'The archive row must never be deleted.');
            $this->assertSame('reconciled', $archived->reconciled_status, "Archive row {$id}'s reconciled_status must be unchanged.");
        }

        // Rows outside day D (an adjacent day's own orphans) are untouched.
        foreach ($adjacentIds as $id) {
            $this->assertNotNull(
                DB::table('transaction_taxes')->where('id', $id)->first(),
                "Adjacent day's orphan {$id} must survive day D's delete()."
            );
        }
    }

    /**
     * Never touches a linked row: seed transaction_pk NOT NULL rows dated
     * within day D — including ones sharing tax_type/amount with the
     * deleted orphans — and assert they all survive delete() untouched.
     */
    public function test_delete_never_touches_a_linked_row_even_when_it_shares_tax_type_and_amount(): void
    {
        $day = Carbon::parse('2027-06-06 00:00:00');

        $orphanIds = $this->seedReconciledDay($day, 2);

        $transactionId = Transaction::factory()->create(['created_at' => $day->copy()->addHours(1)])->id;

        $linkedSameShapeId = $this->insertLinkedRow($transactionId, $day->copy()->addMinutes(30), 'VAT', '10.00');
        $linkedDifferentShapeId = $this->insertLinkedRow($transactionId, $day->copy()->addMinutes(40), 'OTHER', '99.99');

        $preflight = $this->deleter()->preflight($day);
        $result = $this->deleter()->delete($day, $preflight['authorization_token'], 1000);

        $this->assertTrue($result['authorized']);
        $this->assertSame(2, $result['rows_deleted']);

        foreach ($orphanIds as $id) {
            $this->assertNull(DB::table('transaction_taxes')->where('id', $id)->first());
        }

        $survivingLinked = DB::table('transaction_taxes')->whereIn('id', [$linkedSameShapeId, $linkedDifferentShapeId])->get();
        $this->assertCount(2, $survivingLinked, 'Both linked rows must survive, including the one sharing tax_type/amount with a deleted orphan.');

        foreach ($survivingLinked as $row) {
            $this->assertSame($transactionId, $row->transaction_pk);
        }
    }

    // -- 2026-06-13-style uniform no_replacement_exists deletion -------------

    public function test_residual_no_replacement_exists_day_deletes_identically_to_a_plain_reconciled_day(): void
    {
        $reconciledDay = Carbon::parse('2027-06-07 00:00:00');
        $residualDay = Carbon::parse('2027-06-08 00:00:00');

        $reconciledIds = $this->seedReconciledDay($reconciledDay, 3, 'reconciled', null);
        $residualIds = $this->seedReconciledDay($residualDay, 3, 'residual', 'no_replacement_exists');

        $reconciledPreflight = $this->deleter()->preflight($reconciledDay);
        $residualPreflight = $this->deleter()->preflight($residualDay);

        $this->assertTrue($reconciledPreflight['passed']);
        $this->assertTrue($residualPreflight['passed'], 'A uniformly residual/no_replacement_exists day must pass preflight exactly like a plain reconciled day -- no special case.');

        $reconciledResult = $this->deleter()->delete($reconciledDay, $reconciledPreflight['authorization_token'], 1000);
        $residualResult = $this->deleter()->delete($residualDay, $residualPreflight['authorization_token'], 1000);

        $this->assertTrue($reconciledResult['authorized']);
        $this->assertTrue($residualResult['authorized']);
        $this->assertSame($reconciledResult['rows_deleted'], $residualResult['rows_deleted']);
        $this->assertSame(3, $residualResult['rows_deleted']);

        foreach (array_merge($reconciledIds, $residualIds) as $id) {
            $this->assertNull(DB::table('transaction_taxes')->where('id', $id)->first());
        }
    }

    // -- Chunking -------------------------------------------------------------

    public function test_chunking_uses_multiple_bounded_delete_statements_not_one_bulk_statement(): void
    {
        $day = Carbon::parse('2027-06-09 00:00:00');
        $ids = $this->seedReconciledDay($day, 5);

        $preflight = $this->deleter()->preflight($day);
        $this->assertSame(5, $preflight['preview_count']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->deleter()->delete($day, $preflight['authorization_token'], 2);

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($result['authorized']);
        $this->assertSame(3, $result['chunks_processed'], 'ceil(5/2) = 3 chunks.');
        $this->assertSame(5, $result['rows_deleted']);

        $deleteQueries = array_values(array_filter(
            $queryLog,
            fn ($entry) => str_starts_with(strtoupper(ltrim($entry['query'])), 'DELETE ')
        ));

        $this->assertCount(3, $deleteQueries, 'Every intended row must be gone via multiple bounded DELETE statements, never one bulk statement.');

        foreach ($deleteQueries as $entry) {
            $this->assertStringContainsString('transaction_pk', $entry['query']);
        }

        foreach ($ids as $id) {
            $this->assertNull(DB::table('transaction_taxes')->where('id', $id)->first(), "Orphan {$id} must be gone despite chunking.");
        }
    }

    // -- Idempotency ------------------------------------------------------------

    public function test_delete_is_idempotent_when_run_twice_with_the_identical_token(): void
    {
        $day = Carbon::parse('2027-06-10 00:00:00');
        $ids = $this->seedReconciledDay($day, 3);

        $preflight = $this->deleter()->preflight($day);
        $token = $preflight['authorization_token'];

        $firstRun = $this->deleter()->delete($day, $token, 1000);

        $this->assertTrue($firstRun['authorized']);
        $this->assertSame(3, $firstRun['rows_deleted']);
        $this->assertSame(0, $firstRun['already_deleted']);

        foreach ($ids as $id) {
            $this->assertNull(DB::table('transaction_taxes')->where('id', $id)->first());
        }

        $secondRun = $this->deleter()->delete($day, $token, 1000);

        $this->assertTrue($secondRun['authorized'], 'A fully-idempotent re-run must still report success, not an error.');
        $this->assertSame(0, $secondRun['rows_deleted']);
        $this->assertSame(3, $secondRun['already_deleted']);

        foreach ($ids as $id) {
            $archived = DB::table('transaction_taxes_orphan_archive')->where('original_id', $id)->first();
            $this->assertNotNull($archived, 'The archive row must survive both delete() invocations.');
        }
    }

    // -- Hash determinism -------------------------------------------------------

    public function test_hash_is_deterministic_across_repeated_preflight_calls_for_an_unchanged_day(): void
    {
        $day = Carbon::parse('2027-06-11 00:00:00');
        $this->seedReconciledDay($day, 2);

        $first = $this->deleter()->preflight($day);
        $second = $this->deleter()->preflight($day);

        $this->assertSame($first['authorization_token'], $second['authorization_token']);
    }

    public function test_hash_differs_when_a_single_archived_verdict_differs(): void
    {
        $dayA = Carbon::parse('2027-06-12 00:00:00');
        $dayB = Carbon::parse('2027-06-13 00:00:00');

        // Identical shape except the last row's verdict.
        $this->seedReconciledDay($dayA, 2, 'reconciled', null);

        $id1 = $this->insertLiveOrphan($dayB->copy()->addMinutes(0));
        $this->insertArchiveRow($id1, $dayB->copy()->addMinutes(0), 'reconciled', null);
        $id2 = $this->insertLiveOrphan($dayB->copy()->addMinutes(1));
        $this->insertArchiveRow($id2, $dayB->copy()->addMinutes(1), 'residual', 'timestamp_out_of_tolerance');

        $resultA = $this->deleter()->preflight($dayA);
        $resultB = $this->deleter()->preflight($dayB);

        $this->assertNotSame($resultA['authorization_token'], $resultB['authorization_token']);
    }

    // -- Day-binding --------------------------------------------------------

    public function test_hash_is_day_bound_two_days_with_identical_archived_row_shape_produce_different_hashes(): void
    {
        $dayD = Carbon::parse('2027-06-14 00:00:00');
        $dayD1 = Carbon::parse('2027-06-15 00:00:00');

        // Same shape: exactly 2 rows, both 'reconciled'/null, on each day.
        $this->seedReconciledDay($dayD, 2, 'reconciled', null);
        $this->seedReconciledDay($dayD1, 2, 'reconciled', null);

        $resultD = $this->deleter()->preflight($dayD);
        $resultD1 = $this->deleter()->preflight($dayD1);

        $this->assertTrue($resultD['passed']);
        $this->assertTrue($resultD1['passed']);
        $this->assertNotSame(
            $resultD['authorization_token'],
            $resultD1['authorization_token'],
            'Two different days with an identical archived-row shape must still produce different hashes because the day itself is part of the hashed input.'
        );
    }

    public function test_a_days_token_is_rejected_against_the_following_day_even_when_its_own_preconditions_pass(): void
    {
        $dayD = Carbon::parse('2027-06-16 00:00:00');
        $dayD1 = Carbon::parse('2027-06-17 00:00:00');

        $this->seedReconciledDay($dayD, 2, 'reconciled', null);
        $idsD1 = $this->seedReconciledDay($dayD1, 2, 'reconciled', null);

        $preflightD = $this->deleter()->preflight($dayD);
        $preflightD1 = $this->deleter()->preflight($dayD1);

        $this->assertTrue($preflightD1['passed'], "Day D+1's own preconditions must independently pass.");

        $result = $this->deleter()->delete($dayD1, $preflightD['authorization_token'], 1000);

        $this->assertFalse($result['authorized']);
        $this->assertSame('token_mismatch', $result['refusal_reason']);

        foreach ($idsD1 as $id) {
            $this->assertNotNull(DB::table('transaction_taxes')->where('id', $id)->first(), "Day D+1's orphans must survive day D's rejected token.");
        }
    }

    // -- Retry coverage (Slice 17, T100) -------------------------------------

    /**
     * 002-backfill-transaction-taxes, Slice 17 (T100) — operational
     * watchdog controls. See
     * specs/002-backfill-transaction-taxes/slice-17-operational-watchdog-brief.md.
     *
     * Proves delete()'s new DeadlockRetryService::withDeadlockRetry()
     * wrapping around its per-chunk DELETE actually retries (does not
     * immediately fail) on a genuine QueryException matching a lock-wait/
     * deadlock message — mirroring TaxBackfillRunnerTest's own equivalent
     * regression test for TaxBackfillRunner's insert path, reusing the same
     * shared PDO-statement-swap double (Tests\Support\
     * DeadlockOnceOnMatchStatement).
     *
     * Unlike OrphanTaxReconcilerTest's own retry test, this DOES need the
     * DB::commit() flattening workaround: delete()'s DELETE is wrapped with
     * the default $wrapInTransaction = true (matching TaxBackfillRunner's own
     * shape exactly — see delete()'s docblock), so withDeadlockRetry() opens
     * its own fresh DB::transaction($callback, 1) per attempt. Left nested
     * inside this test's own ambient RefreshDatabase transaction, a caught
     * "Deadlock found"/"Lock wait timeout" QueryException at transaction
     * depth > 1 is converted by Laravel itself into
     * Illuminate\Database\DeadlockException (a PDOException subclass, NOT a
     * QueryException) — which withDeadlockRetry()'s `catch (QueryException
     * $e)` can never catch, defeating retry on the very first attempt. This
     * is exactly the hazard TaxBackfillRunner::processTransactionForApply()'s
     * own docblock documents; DB::commit() flattens the connection to
     * transaction depth 0 first, so withDeadlockRetry()'s own transaction is
     * the outermost one — genuine, uncoverted QueryException retry, matching
     * this method's real (non-nested) production call path from the CLI
     * command.
     */
    public function test_delete_retries_a_simulated_lock_wait_timeout_and_the_second_attempt_succeeds(): void
    {
        $day = Carbon::parse('2027-06-19 00:00:00');

        DB::commit();

        $ids = [];
        $pdo = null;
        $defaultStatementClass = null;

        try {
            $ids = $this->seedReconciledDay($day, 2);

            $preflight = $this->deleter()->preflight($day);
            $this->assertTrue($preflight['passed']);

            $pdo = DB::connection()->getPdo();
            $defaultStatementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DeadlockOnceOnMatchStatement::class, []]);
            DeadlockOnceOnMatchStatement::$matcher = fn (string $sql) => str_starts_with(strtolower(trim($sql)), 'delete')
                && str_contains($sql, 'transaction_taxes');
            DeadlockOnceOnMatchStatement::$remainingFailures = 1;

            $result = $this->deleter()->delete($day, $preflight['authorization_token'], 1000);

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);

            $this->assertSame(
                1,
                DeadlockOnceOnMatchStatement::$timesTriggered,
                'The forced lock-wait-timeout never actually triggered on the DELETE — this test would otherwise pass vacuously.'
            );
            $this->assertTrue($result['authorized']);
            $this->assertSame(2, $result['rows_deleted']);
            $this->assertSame(0, $result['already_deleted']);

            foreach ($ids as $id) {
                $this->assertNull(
                    DB::table('transaction_taxes')->where('id', $id)->first(),
                    'Expected the retried second attempt to have actually deleted this row.'
                );
            }
        } finally {
            if ($pdo !== null && $defaultStatementClass !== null) {
                $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);
            }
            DeadlockOnceOnMatchStatement::reset();

            // DB::commit() above made this test's fixtures (and the DELETE
            // it performs) permanent — RefreshDatabase's usual rollback can
            // no longer undo them, so clean up explicitly.
            DB::table('transaction_taxes')->whereIn('id', $ids)->delete();
            DB::table('transaction_taxes_orphan_archive')->whereIn('original_id', $ids)->delete();

            // Reopen an empty transaction so RefreshDatabase's teardown
            // rollback behaves exactly as it would for any other test.
            DB::beginTransaction();
        }
    }
}
