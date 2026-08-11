<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Backfill;

use App\Services\Backfill\OrphanTaxArchiver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 12 (T067/T072) — Stage 1 of 3 for
 * the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
 *
 * Covers OrphanTaxArchiver::archive() directly: byte-faithful copying,
 * that nothing is ever deleted, idempotency (including a direct DB-level
 * unique-constraint violation, not just an application-level assertion),
 * and resumability after a simulated partial run.
 *
 * Counts/assertions below are scoped to this test's own fixture ids
 * (never table-wide), matching this feature's established discipline for
 * the known RefreshDatabase isolation gap tracked at tests/TestCase.php:38.
 */
class OrphanTaxArchiverTest extends TestCase
{
    private function archiver(): OrphanTaxArchiver
    {
        return new OrphanTaxArchiver;
    }

    /**
     * Inserts a single orphan (transaction_pk IS NULL) row directly into
     * transaction_taxes, bypassing TransactionTax's Eloquent
     * timestamps so created_at/updated_at can be set to arbitrary,
     * independently-known values for the byte-faithful assertions below.
     */
    private function insertOrphanRow(array $attributes = []): int
    {
        return DB::table('transaction_taxes')->insertGetId(array_merge([
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => 12.34,
            'created_at' => '2026-01-05 10:15:00',
            'updated_at' => '2026-01-05 10:16:00',
        ], $attributes));
    }

    private function archiveRowFor(int $originalId): ?object
    {
        return DB::table('transaction_taxes_orphan_archive')
            ->where('original_id', $originalId)
            ->first();
    }

    /**
     * Independently computes what OrphanTaxArchiver::archive() should return
     * for the table's CURRENT state, using the same "orphan" / "already
     * archived" conditions the service itself relies on. Needed because
     * archive() deliberately scans the whole `transaction_taxes` orphan
     * population (never day/tenant-scoped, per this slice's design) — so its
     * return value reflects every orphan in the table, not just a given
     * test's own fixtures. This repo's shared test database is not
     * guaranteed to be empty of orphans left behind by earlier tests (the
     * known RefreshDatabase isolation gap tracked at tests/TestCase.php:38),
     * so asserting against a hardcoded "0 pre-existing orphans" baseline
     * would be flaky. Computing the expectation from the live table instead
     * makes the assertion exact and correct regardless of what pollution, if
     * any, is already present.
     *
     * @return array{processed: int, newly_archived: int, already_archived: int}
     */
    private function expectedArchiveCounts(): array
    {
        $totalOrphans = DB::table('transaction_taxes')
            ->whereNull('transaction_pk')
            ->count();

        $alreadyArchived = DB::table('transaction_taxes as t')
            ->join('transaction_taxes_orphan_archive as a', 'a.original_id', '=', 't.id')
            ->whereNull('t.transaction_pk')
            ->count();

        return [
            'processed' => $totalOrphans,
            'newly_archived' => $totalOrphans - $alreadyArchived,
            'already_archived' => $alreadyArchived,
        ];
    }

    public function test_archive_is_byte_faithful_to_the_source_row(): void
    {
        $idVat = $this->insertOrphanRow([
            'tax_type' => 'VAT',
            'amount' => 45.67,
            'created_at' => '2026-02-01 08:30:15',
            'updated_at' => '2026-02-01 08:31:20',
        ]);

        $idOther = $this->insertOrphanRow([
            'tax_type' => 'OTHER',
            'amount' => 0.00,
            'created_at' => '2026-02-02 00:00:00',
            'updated_at' => null,
        ]);

        $summary = $this->archiver()->archive();

        $this->assertSame(2, $summary['newly_archived']);

        $vatArchived = $this->archiveRowFor($idVat);
        $otherArchived = $this->archiveRowFor($idOther);

        $this->assertNotNull($vatArchived);
        $this->assertNotNull($otherArchived);

        // Field-for-field equality against the SOURCE row's own values —
        // not the archive operation's own archived_at timestamp.
        $this->assertSame($idVat, $vatArchived->original_id);
        $this->assertNull($vatArchived->transaction_pk);
        $this->assertSame('VAT', $vatArchived->tax_type);
        $this->assertSame('45.67', $vatArchived->amount);
        $this->assertSame('2026-02-01 08:30:15', $vatArchived->created_at);
        $this->assertSame('2026-02-01 08:31:20', $vatArchived->updated_at);

        $this->assertSame($idOther, $otherArchived->original_id);
        $this->assertNull($otherArchived->transaction_pk);
        $this->assertSame('OTHER', $otherArchived->tax_type);
        $this->assertSame('0.00', $otherArchived->amount);
        $this->assertSame('2026-02-02 00:00:00', $otherArchived->created_at);
        $this->assertNull($otherArchived->updated_at);

        // archived_at is new/different from the source's own timestamps,
        // and shared as the same archive_run_id across the whole invocation.
        $this->assertNotNull($vatArchived->archived_at);
        $this->assertNotSame($vatArchived->created_at, $vatArchived->archived_at);
        $this->assertSame($vatArchived->archive_run_id, $otherArchived->archive_run_id);
        $this->assertTrue(Str::isUuid($vatArchived->archive_run_id));

        // Stage 2's columns are left untouched by this slice.
        $this->assertNull($vatArchived->reconciled_status);
        $this->assertNull($vatArchived->reason_code);
    }

    public function test_archiving_does_not_delete_or_modify_the_original_rows(): void
    {
        $id = $this->insertOrphanRow([
            'tax_type' => 'VAT',
            'amount' => 99.99,
            'created_at' => '2026-03-01 12:00:00',
            'updated_at' => '2026-03-01 12:00:00',
        ]);

        $this->archiver()->archive();

        $original = DB::table('transaction_taxes')->where('id', $id)->first();

        $this->assertNotNull($original, 'The original orphan row must still exist in transaction_taxes after archiving.');
        $this->assertNull($original->transaction_pk);
        $this->assertSame('VAT', $original->tax_type);
        $this->assertSame('99.99', $original->amount);
        $this->assertSame('2026-03-01 12:00:00', $original->created_at);
        $this->assertSame('2026-03-01 12:00:00', $original->updated_at);
    }

    public function test_running_archive_twice_is_idempotent_with_zero_duplicates(): void
    {
        $ids = [
            $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 1.00]),
            $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 2.00]),
            $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 3.00]),
        ];

        // Computed from the live table rather than assumed to be a clean
        // 0/0 baseline — see expectedArchiveCounts()'s docblock.
        $expectedFirst = $this->expectedArchiveCounts();

        $first = $this->archiver()->archive();

        $this->assertSame($expectedFirst, $first);
        $this->assertGreaterThanOrEqual(3, $first['newly_archived'], 'This test\'s own 3 fresh orphans must all have been newly archived.');

        $second = $this->archiver()->archive();

        // Always true regardless of any pre-existing archived/orphan rows:
        // nothing is left to newly archive on the second pass, and the
        // second pass's already_archived total is exactly everything the
        // first pass just processed (its newly_archived + already_archived).
        $this->assertSame(0, $second['newly_archived']);
        $this->assertSame($first['newly_archived'] + $first['already_archived'], $second['already_archived']);

        $archivedCount = DB::table('transaction_taxes_orphan_archive')
            ->whereIn('original_id', $ids)
            ->count();

        $this->assertSame(count($ids), $archivedCount, 'Each orphan must be archived exactly once, even after running archive() twice.');
    }

    /**
     * Proves the idempotency guarantee holds at the DB level, not merely
     * because the application code happens to avoid duplicates: a raw
     * insert() (bypassing insertOrIgnore()) with a duplicate original_id
     * must be rejected by the unique constraint itself.
     */
    public function test_unique_constraint_on_original_id_is_enforced_at_the_database_level(): void
    {
        $id = $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 5.00]);

        $this->archiver()->archive();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('transaction_taxes_orphan_archive')->insert([
            'original_id' => $id,
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => 5.00,
            'created_at' => now(),
            'updated_at' => now(),
            'archive_run_id' => (string) Str::uuid(),
            'archived_at' => now(),
            'reconciled_status' => null,
            'reason_code' => null,
        ]);
    }

    public function test_resumability_after_a_simulated_partial_run(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => (string) ($i + 1)]);
        }

        // Simulate a partial first run by directly archiving a subset,
        // exactly as if an earlier invocation of archive() had been
        // interrupted after its first chunk.
        $preArchivedIds = array_slice($ids, 0, 2);

        foreach ($preArchivedIds as $preArchivedId) {
            DB::table('transaction_taxes_orphan_archive')->insert([
                'original_id' => $preArchivedId,
                'transaction_pk' => null,
                'tax_type' => 'VAT',
                'amount' => 1.00,
                'created_at' => now(),
                'updated_at' => now(),
                'archive_run_id' => 'simulated-partial-run',
                'archived_at' => now(),
                'reconciled_status' => null,
                'reason_code' => null,
            ]);
        }

        // Computed from the live table (including the 2 pre-archived ids
        // just inserted above) rather than assumed against a clean baseline
        // — see expectedArchiveCounts()'s docblock.
        $expected = $this->expectedArchiveCounts();

        // Small chunk size so the run to completion spans multiple chunks.
        $summary = $this->archiver()->archive(2);

        $this->assertSame($expected, $summary);

        $archivedCount = DB::table('transaction_taxes_orphan_archive')
            ->whereIn('original_id', $ids)
            ->count();

        $this->assertSame(5, $archivedCount, 'Every orphan must end up archived exactly once, whether pre-archived or newly archived in this run.');
    }

    public function test_archive_never_touches_the_transactions_table(): void
    {
        $watermark = (int) (DB::table('transactions')->max('id') ?? 0);

        $this->insertOrphanRow(['tax_type' => 'VAT', 'amount' => 7.50]);

        $this->archiver()->archive();

        $this->assertSame(
            $watermark,
            (int) (DB::table('transactions')->max('id') ?? 0),
            'OrphanTaxArchiver must never write to the transactions table.'
        );
    }
}
