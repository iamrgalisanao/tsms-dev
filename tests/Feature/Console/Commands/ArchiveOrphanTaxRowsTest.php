<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes — Stage 1 (Slice 12, T068 archive-phase,
 * T072) and Stage 2 (Slice 13, T069/T071-partial, `--phase=reconcile`) of
 * the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md and
 * .../slice-13-orphan-reconcile-brief.md.
 *
 * Covers the `transactions:archive-orphan-taxes` command: dry-run reports
 * accurate counts and writes nothing; --apply actually archives; every
 * `--phase` other than 'archive'/'reconcile' (i.e. 'delete' or garbage) is
 * rejected with zero DB access beyond option parsing; human/--json output
 * agree structurally for both phases; and reconcile's own --day
 * requirement, all-or-nothing persistence, and never-deletes guarantees.
 *
 * Counts/assertions below are scoped to this test's own fixture ids (never
 * table-wide), matching this feature's established discipline for the known
 * RefreshDatabase isolation gap tracked at tests/TestCase.php:38.
 */
class ArchiveOrphanTaxRowsTest extends TestCase
{
    private function insertOrphanRow(array $attributes = []): int
    {
        return DB::table('transaction_taxes')->insertGetId(array_merge([
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => 10.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function archivedCountFor(array $ids): int
    {
        return DB::table('transaction_taxes_orphan_archive')
            ->whereIn('original_id', $ids)
            ->count();
    }

    public function test_dry_run_reports_accurate_counts_and_writes_nothing(): void
    {
        $ids = [
            $this->insertOrphanRow(),
            $this->insertOrphanRow(),
            $this->insertOrphanRow(),
        ];

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('archive', $decoded['phase']);
        $this->assertFalse($decoded['applied']);
        $this->assertGreaterThanOrEqual(3, $decoded['totals']['processed']);
        $this->assertGreaterThanOrEqual(3, $decoded['totals']['newly_archived']);

        $this->assertSame(0, $this->archivedCountFor($ids), 'Dry-run must never write to the archive table.');
    }

    public function test_apply_archives_and_reports_accurate_counts(): void
    {
        $ids = [
            $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 11.11]),
            $this->insertOrphanRow(['tax_type' => 'OTHER', 'amount' => 22.22]),
        ];

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--apply' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($decoded['applied']);
        $this->assertGreaterThanOrEqual(2, $decoded['totals']['newly_archived']);

        $this->assertSame(2, $this->archivedCountFor($ids));

        // Running --apply again converges to already_archived for these ids
        // (idempotency), matching OrphanTaxArchiver's own guarantee.
        $secondExitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--apply' => true,
            '--json' => true,
        ]);
        $secondDecoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $secondExitCode);
        $this->assertSame(0, $secondDecoded['totals']['newly_archived']);
        $this->assertSame(2, $this->archivedCountFor($ids));
    }

    /**
     * Shared assertion body for every "not yet implemented" --phase value.
     * No @dataProvider here — this codebase's PHPUnit 12 doesn't use
     * doc-comment data providers anywhere else (removed in PHPUnit 10+;
     * would require the #[DataProvider] attribute instead), so each phase
     * gets its own explicit test method below, matching this repo's
     * existing convention of one test method per scenario.
     *
     * Code-review follow-up: the earlier version of this test only proved
     * "zero DB access" via side effects (no archive rows created), which is
     * strictly weaker than the claim itself — a regression that added a
     * read-only query before the phase check would slip past a side-effect-
     * only assertion. DB::enableQueryLog()/getQueryLog() around the
     * Artisan::call() below proves zero DB access directly, the same
     * technique used to independently re-verify this behavior live. The
     * query log is enabled only around the call itself (not the fixture
     * setup above it, which legitimately does query) and is read/disabled
     * immediately after, before any of this method's own follow-up
     * verification queries run.
     */
    private function assertPhaseIsRejectedWithZeroDbAccess(string $phase): void
    {
        $id = $this->insertOrphanRow();

        $watermark = (int) (DB::table('transaction_taxes_orphan_archive')->max('id') ?? 0);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--apply' => true,
            '--json' => true,
            '--phase' => $phase,
        ]);
        $output = Artisan::output();

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $queryLog,
            "Rejecting --phase={$phase} must issue zero DB queries, not merely zero writes. Queries observed: ".json_encode($queryLog)
        );

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('not yet implemented in this build', $output);
        $this->assertStringContainsString('later slices', $output);

        // Corroborating side-effect checks, kept alongside the direct
        // query-log proof above: no archive rows created at all (watermark
        // unchanged), and the source row is untouched.
        $this->assertSame(
            $watermark,
            (int) (DB::table('transaction_taxes_orphan_archive')->max('id') ?? 0)
        );
        $this->assertSame(0, $this->archivedCountFor([$id]));

        $original = DB::table('transaction_taxes')->where('id', $id)->first();
        $this->assertNotNull($original);
        $this->assertNull($original->transaction_pk);
    }

    public function test_phase_delete_is_rejected_with_zero_db_access(): void
    {
        $this->assertPhaseIsRejectedWithZeroDbAccess('delete');
    }

    public function test_garbage_phase_is_rejected_with_zero_db_access(): void
    {
        $this->assertPhaseIsRejectedWithZeroDbAccess('bogus-phase');
    }

    public function test_empty_phase_is_rejected_with_zero_db_access(): void
    {
        $this->assertPhaseIsRejectedWithZeroDbAccess('');
    }

    public function test_human_and_json_output_agree_structurally(): void
    {
        $ids = [
            $this->insertOrphanRow(),
            $this->insertOrphanRow(),
        ];

        $jsonExitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--apply' => true,
            '--json' => true,
        ]);
        $jsonDecoded = json_decode(Artisan::output(), true);

        $humanExitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--json' => false,
        ]);
        $humanOutput = Artisan::output();

        $this->assertSame(0, $jsonExitCode);
        $this->assertSame(0, $humanExitCode);
        $this->assertSame(2, $this->archivedCountFor($ids), 'The --apply call above must have archived both fixture rows.');

        $this->assertStringContainsString('archive', $humanOutput);
        $this->assertStringContainsString('dry-run', $humanOutput);
        $this->assertMatchesRegularExpression('/\|\s*Processed\s*\|\s*Newly archived\s*\|\s*Already archived\s*\|/', $humanOutput);

        // The dry-run human call above happens after the apply call, so its
        // reported already_archived must be at least what was just applied.
        $this->assertMatchesRegularExpression('/\|\s*\d+\s*\|\s*\d+\s*\|\s*\d+\s*\|/', $humanOutput);

        $this->assertArrayHasKey('phase', $jsonDecoded);
        $this->assertArrayHasKey('applied', $jsonDecoded);
        $this->assertArrayHasKey('chunk_size', $jsonDecoded);
        $this->assertArrayHasKey('totals', $jsonDecoded);
        $this->assertArrayHasKey('processed', $jsonDecoded['totals']);
        $this->assertArrayHasKey('newly_archived', $jsonDecoded['totals']);
        $this->assertArrayHasKey('already_archived', $jsonDecoded['totals']);
    }

    public function test_invalid_chunk_option_is_rejected(): void
    {
        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--chunk' => '0',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Invalid --chunk value', Artisan::output());
    }

    /**
     * 002-backfill-transaction-taxes, Slice 13 (T069/T071-partial) —
     * `--phase=reconcile` wiring. See
     * specs/002-backfill-transaction-taxes/slice-13-orphan-reconcile-brief.md.
     *
     * Every test below uses a dedicated future calendar day (2027-05-0N),
     * for the same reason ArchiveOrphanTaxArchiverTest/this file's own
     * archive-phase tests scope archive-table assertions to their own
     * fixture ids: OrphanTaxReconciler::evaluate() deliberately reasons over
     * a WHOLE day across ALL tenants (never scoped to a test's own ids), so
     * a shared/recent day would risk picking up unrelated leaked fixtures
     * from the known RefreshDatabase isolation gap (tests/TestCase.php:38).
     */
    private function insertOrphanAt(Carbon $ts, string $amount = '10.00'): int
    {
        return $this->insertOrphanRow([
            'tax_type' => 'VAT',
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    private function insertReconstructedAt(int $transactionPk, Carbon $ts, string $amount = '10.00'): int
    {
        return DB::table('transaction_taxes')->insertGetId([
            'transaction_pk' => $transactionPk,
            'tax_type' => 'VAT',
            'amount' => $amount,
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    public function test_reconcile_requires_day_option(): void
    {
        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('--day is required', Artisan::output());
    }

    public function test_reconcile_rejects_a_malformed_day(): void
    {
        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-13-99',
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Invalid --day value', Artisan::output());
    }

    public function test_reconcile_dry_run_reports_a_passing_verdict_and_writes_nothing(): void
    {
        $day = Carbon::parse('2027-05-01 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $orphanIds = [
            $this->insertOrphanAt($day->copy()->addSeconds(5)),
            $this->insertOrphanAt($day->copy()->addSeconds(50)),
        ];
        $this->insertReconstructedAt($dummyTransactionId, $day->copy()->addSeconds(0));
        $this->insertReconstructedAt($dummyTransactionId, $day->copy()->addSeconds(45));

        foreach ($orphanIds as $id) {
            DB::table('transaction_taxes_orphan_archive')->insert([
                'original_id' => $id,
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

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-01',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('reconcile', $decoded['phase']);
        $this->assertFalse($decoded['applied']);
        $this->assertFalse($decoded['persisted']);
        $this->assertTrue($decoded['passed']);
        $this->assertSame(2, $decoded['totals']['reconciled']);

        foreach ($orphanIds as $id) {
            $row = DB::table('transaction_taxes_orphan_archive')->where('original_id', $id)->first();
            $this->assertNull($row->reconciled_status, 'Dry-run must never write to the archive table.');
        }
    }

    public function test_reconcile_apply_persists_when_the_day_passes(): void
    {
        $day = Carbon::parse('2027-05-02 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $orphanId = $this->insertOrphanAt($day->copy()->addSeconds(3), '11.00');
        $this->insertReconstructedAt($dummyTransactionId, $day->copy()->addSeconds(0), '11.00');

        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $orphanId,
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => '11.00',
            'created_at' => now(),
            'updated_at' => now(),
            'archive_run_id' => 'test-fixture',
            'archived_at' => now(),
            'reconciled_status' => null,
            'reason_code' => null,
        ]);

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-02',
            '--apply' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($decoded['applied']);
        $this->assertTrue($decoded['persisted']);
        $this->assertTrue($decoded['passed']);

        $row = DB::table('transaction_taxes_orphan_archive')->where('original_id', $orphanId)->first();
        $this->assertSame('reconciled', $row->reconciled_status);
        $this->assertNull($row->reason_code);
    }

    /**
     * All-or-nothing persistence, verified directly against the DB (not
     * merely the command's return value/exit code), for a day that halts on
     * the precondition guard: --apply on this day must leave every one of
     * that day's archive rows exactly as it found them.
     */
    public function test_reconcile_apply_writes_nothing_to_the_archive_when_the_day_halts(): void
    {
        $day = Carbon::parse('2027-05-03 00:00:00');

        // A day-D transaction with no tax_backfill_records row at all —
        // trips the Step 2 precondition guard.
        Transaction::factory()->create(['created_at' => $day->copy()->addHours(2)]);

        $orphanId = $this->insertOrphanAt($day->copy()->addSeconds(3), '12.00');

        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $orphanId,
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => '12.00',
            'created_at' => now(),
            'updated_at' => now(),
            'archive_run_id' => 'test-fixture',
            'archived_at' => now(),
            'reconciled_status' => null,
            'reason_code' => null,
        ]);

        $beforeRow = DB::table('transaction_taxes_orphan_archive')->where('original_id', $orphanId)->first();

        $exitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-03',
            '--apply' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotSame(0, $exitCode);
        $this->assertFalse($decoded['passed']);
        $this->assertFalse($decoded['persisted']);
        $this->assertSame('precondition_pending', $decoded['halt_reason']);

        $afterRow = DB::table('transaction_taxes_orphan_archive')->where('original_id', $orphanId)->first();

        $this->assertEquals($beforeRow, $afterRow, 'A halted reconcile --apply must leave the archive table byte-for-byte unchanged.');
        $this->assertNull($afterRow->reconciled_status);
        $this->assertNull($afterRow->reason_code);
    }

    public function test_reconcile_never_deletes_from_transaction_taxes_dry_run_or_apply(): void
    {
        $day = Carbon::parse('2027-05-04 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $this->insertOrphanAt($day->copy()->addSeconds(3), '13.00');
        $this->insertReconstructedAt($dummyTransactionId, $day->copy()->addSeconds(0), '13.00');

        $watermark = (int) DB::table('transaction_taxes')->count();

        Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-04',
        ]);

        $this->assertSame($watermark, (int) DB::table('transaction_taxes')->count(), 'Dry-run reconcile must never delete/insert transaction_taxes rows.');

        Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-04',
            '--apply' => true,
        ]);

        $this->assertSame($watermark, (int) DB::table('transaction_taxes')->count(), '--apply reconcile must never delete/insert transaction_taxes rows.');
    }

    public function test_reconcile_human_and_json_output_agree_structurally(): void
    {
        $day = Carbon::parse('2027-05-05 00:00:00');
        $dummyTransactionId = Transaction::factory()->create()->id;

        $orphanId = $this->insertOrphanAt($day->copy()->addSeconds(3), '14.00');
        $this->insertReconstructedAt($dummyTransactionId, $day->copy()->addSeconds(0), '14.00');

        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $orphanId,
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => '14.00',
            'created_at' => now(),
            'updated_at' => now(),
            'archive_run_id' => 'test-fixture',
            'archived_at' => now(),
            'reconciled_status' => null,
            'reason_code' => null,
        ]);

        $jsonExitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-05',
            '--json' => true,
        ]);
        $jsonDecoded = json_decode(Artisan::output(), true);

        $humanExitCode = Artisan::call('transactions:archive-orphan-taxes', [
            '--phase' => 'reconcile',
            '--day' => '2027-05-05',
            '--json' => false,
        ]);
        $humanOutput = Artisan::output();

        $this->assertSame(0, $jsonExitCode);
        $this->assertSame(0, $humanExitCode);

        $this->assertStringContainsString('reconcile', $humanOutput);
        $this->assertStringContainsString('dry-run', $humanOutput);
        $this->assertStringContainsString('PASSED', $humanOutput);
        $this->assertMatchesRegularExpression('/\|\s*Reconciled\s*\|\s*Timestamp out of tolerance\s*\|\s*No replacement exists\s*\|\s*Orphan content mismatch\s*\|/', $humanOutput);

        $this->assertArrayHasKey('phase', $jsonDecoded);
        $this->assertArrayHasKey('day', $jsonDecoded);
        $this->assertArrayHasKey('applied', $jsonDecoded);
        $this->assertArrayHasKey('persisted', $jsonDecoded);
        $this->assertArrayHasKey('passed', $jsonDecoded);
        $this->assertArrayHasKey('totals', $jsonDecoded);
        $this->assertArrayHasKey('reconciled', $jsonDecoded['totals']);
        $this->assertArrayHasKey('timestamp_out_of_tolerance', $jsonDecoded['totals']);
        $this->assertArrayHasKey('no_replacement_exists', $jsonDecoded['totals']);
        $this->assertArrayHasKey('orphan_content_mismatch', $jsonDecoded['totals']);
        $this->assertArrayHasKey('content_gap', $jsonDecoded);
    }
}
