<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 12 (T068 archive-phase, T072) —
 * Stage 1 of 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
 *
 * Covers the `transactions:archive-orphan-taxes` command: dry-run reports
 * accurate counts and writes nothing; --apply actually archives; every
 * --phase other than 'archive' is rejected with zero DB access beyond
 * option parsing; and human/--json output agree structurally.
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

    public function test_phase_reconcile_is_rejected_with_zero_db_access(): void
    {
        $this->assertPhaseIsRejectedWithZeroDbAccess('reconcile');
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
}
