<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\PreRunIntegrityCapture;
use App\Models\Transaction;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 16 (T075/T099). See
 * specs/002-backfill-transaction-taxes/slice-16-integrity-evidence-capture-brief.md,
 * "Test plan".
 *
 * Covers `transactions:capture-integrity-evidence`: the corrected T062
 * duplicate-check query's NULL-collapse regression and real-duplicate
 * detection, verbatim capture of `txn:pk-integrity`'s output, a
 * structural/spy proof that `txn:pk-integrity` is actually invoked (not
 * reimplemented), durable persistence, multi-capture coexistence,
 * dry-run-writes-nothing, zero mutation of transaction_taxes/transactions,
 * and CLI-level validation/output parity.
 *
 * Known test-isolation issue (tests/TestCase.php:38 breaks RefreshDatabase —
 * rows can leak between tests within the same run): because the duplicate
 * check this command runs is deliberately whole-table (Design decision 2 of
 * the brief — never scoped to --from/--to), this file follows the same
 * discipline established by
 * tests/Feature/Services/Backfill/OrphanTaxArchiverTest.php's
 * expectedArchiveCounts(): duplicate-check assertions compare the command's
 * result against an independently-computed BEFORE baseline (same query,
 * run directly against the live table) rather than a hardcoded "0"
 * assumption. This is not a weaker test — for the NULL-collapse regression
 * specifically, a baseline-delta assertion is the more precise proof: if the
 * `WHERE transaction_pk IS NOT NULL` clause were ever dropped, the seeded
 * NULL-pk rows would shift the totals away from the baseline by an exact,
 * predictable amount, which the delta assertion would catch regardless of
 * whatever unrelated duplicate rows earlier tests may have left behind.
 *
 * Named CaptureBackfillIntegrityEvidenceTest (not the more literal
 * CaptureIntegrityEvidenceTest) so it is discoverable via this feature's
 * `--filter=Backfill` test-running convention — matching how
 * BackfillTransactionTaxesTest and SnapshotPreBackfillAggregatesTest are
 * already named for the same reason.
 */
class CaptureBackfillIntegrityEvidenceTest extends TestCase
{
    private const COMMAND = 'transactions:capture-integrity-evidence';

    /**
     * Rows this test file has inserted directly into transaction_taxes,
     * deleted in tearDown() below. The duplicate-check query this command
     * runs is deliberately whole-table (never scoped to this test's own
     * fixtures), so unlike most of this feature's other tests, leaving
     * these rows behind would pollute every OTHER test file's transaction_
     * taxes-wide assertions for the rest of the same test run — most
     * concretely, OrphanTaxArchiverTest's hardcoded-count assertions,
     * which (unlike this file's own baseline-delta technique) are not
     * pollution-tolerant. This is a self-contained fix scoped to this
     * file's own fixtures; it does not touch or rely on RefreshDatabase
     * actually working (tests/TestCase.php:38's known break).
     *
     * @var list<int>
     */
    private array $insertedTaxRowIds = [];

    /**
     * Code-review follow-up: this file's `test_a_real_linked_row_duplicate_is_still_caught()`
     * and the multi-capture-coexistence test also create `Transaction::factory()`
     * rows directly, which were left uncleaned — inconsistent with this file's
     * own stated rationale above for aggressively cleaning up shared-table
     * fixtures. Harmless today (no other test hardcodes an absolute
     * `transactions` count), but a latent trap for a future test that does.
     *
     * @var list<int>
     */
    private array $insertedTransactionIds = [];

    protected function tearDown(): void
    {
        if ($this->insertedTaxRowIds !== []) {
            DB::table('transaction_taxes')->whereIn('id', $this->insertedTaxRowIds)->delete();
        }

        if ($this->insertedTransactionIds !== []) {
            DB::table('transactions')->whereIn('id', $this->insertedTransactionIds)->delete();
        }

        parent::tearDown();
    }

    private function insertOrphanRow(array $attributes = []): int
    {
        $id = DB::table('transaction_taxes')->insertGetId(array_merge([
            'transaction_pk' => null,
            'tax_type' => 'VAT',
            'amount' => 10.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        $this->insertedTaxRowIds[] = $id;

        return $id;
    }

    private function insertLinkedRow(int $transactionPk, string $taxType, array $attributes = []): int
    {
        $id = DB::table('transaction_taxes')->insertGetId(array_merge([
            'transaction_pk' => $transactionPk,
            'tax_type' => $taxType,
            'amount' => 10.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        $this->insertedTaxRowIds[] = $id;

        return $id;
    }

    /**
     * Independently computes the corrected T062 duplicate-check totals
     * against the CURRENT live table state — the same oracle technique as
     * OrphanTaxArchiverTest::expectedArchiveCounts(), for the same reason
     * (this repo's shared test database is not guaranteed to be free of
     * rows left behind by earlier tests).
     *
     * @return array{total_duplicate_groups: int, total_duplicate_rows: int}
     */
    private function expectedDuplicateCheckTotals(): array
    {
        $groups = DB::table('transaction_taxes')
            ->select(['transaction_pk', 'tax_type'])
            ->selectRaw('COUNT(*) as cnt')
            ->whereNotNull('transaction_pk')
            ->groupBy('transaction_pk', 'tax_type')
            ->having('cnt', '>', 1)
            ->get();

        return [
            'total_duplicate_groups' => $groups->count(),
            'total_duplicate_rows' => (int) $groups->sum('cnt'),
        ];
    }

    /**
     * The command under test makes its own nested Artisan::call('txn:pk-
     * integrity') call (T099) — Laravel's Artisan facade keeps a single
     * shared "last output" buffer on the underlying console Application, so
     * that nested call overwrites it with the INNER command's output before
     * this outer call returns, making Artisan::output() unreliable here. An
     * explicit BufferedOutput passed as the third argument sidesteps that
     * entirely: it is the exact object this outer command's execute() writes
     * to, unaffected by whatever the nested call does to the Application's
     * own bookkeeping.
     *
     * @return array<string, mixed>
     */
    private function runCommand(array $options = []): array
    {
        $defaults = [
            '--from' => '2030-01-01',
            '--to' => '2030-02-01',
            '--json' => true,
        ];

        $output = new BufferedOutput;

        Artisan::call(self::COMMAND, array_merge($defaults, $options), $output);

        return json_decode($output->fetch(), true);
    }

    public function test_null_collapse_regression_never_counts_null_transaction_pk_rows_as_duplicates(): void
    {
        $baseline = $this->expectedDuplicateCheckTotals();

        // Enough NULL-transaction_pk orphan rows sharing one tax_type that a
        // naive, ungrounded `GROUP BY transaction_pk, tax_type` would report
        // them as one giant duplicate group.
        for ($i = 0; $i < 6; $i++) {
            $this->insertOrphanRow(['tax_type' => 'VAT']);
        }

        $result = $this->runCommand([
            '--from' => '2030-01-01',
            '--to' => '2030-02-01',
        ]);

        $this->assertSame(
            $baseline['total_duplicate_groups'],
            $result['duplicate_check_summary']['total_duplicate_groups'],
            'NULL transaction_pk rows must never contribute a duplicate group — the WHERE transaction_pk IS NOT NULL filter must be applied.'
        );
        $this->assertSame(
            $baseline['total_duplicate_rows'],
            $result['duplicate_check_summary']['total_duplicate_rows']
        );

        foreach ($result['duplicate_check_summary']['sample'] as $group) {
            $this->assertNotNull($group['transaction_pk'], 'No sampled group may carry a NULL transaction_pk.');
        }
    }

    public function test_a_real_linked_row_duplicate_is_still_caught(): void
    {
        $baseline = $this->expectedDuplicateCheckTotals();

        $transaction = Transaction::factory()->create();
        $this->insertedTransactionIds[] = $transaction->id;
        $this->insertLinkedRow($transaction->id, 'VAT');
        $this->insertLinkedRow($transaction->id, 'VAT');

        $result = $this->runCommand([
            '--from' => '2030-02-01',
            '--to' => '2030-03-01',
        ]);

        $this->assertSame(
            $baseline['total_duplicate_groups'] + 1,
            $result['duplicate_check_summary']['total_duplicate_groups']
        );
        $this->assertSame(
            $baseline['total_duplicate_rows'] + 2,
            $result['duplicate_check_summary']['total_duplicate_rows']
        );

        $match = collect($result['duplicate_check_summary']['sample'])
            ->firstWhere('transaction_pk', $transaction->id);

        $this->assertNotNull($match, 'The seeded genuine duplicate must appear in the sample.');
        $this->assertSame('VAT', $match['tax_type']);
        $this->assertSame(2, $match['count']);
    }

    public function test_integrity_report_is_captured_verbatim(): void
    {
        // Independent oracle: call the real command directly, exactly as
        // T099 requires, and compare byte-for-byte.
        Artisan::call('txn:pk-integrity');
        $expected = Artisan::output();

        $result = $this->runCommand([
            '--from' => '2030-03-01',
            '--to' => '2030-04-01',
        ]);

        $this->assertSame($expected, $result['integrity_report']);
        $this->assertStringContainsString('Transaction PK integrity report', $result['integrity_report']);
    }

    /**
     * Structural/spy proof that `txn:pk-integrity` is actually invoked via
     * Artisan::call(), not reimplemented — mirrors this feature's
     * established rigor for "the real path was called, not bespoke logic"
     * (see SnapshotPreBackfillAggregatesTest's spy-verified call count).
     *
     * Deliberately a hand-written decorator around the real bound
     * Illuminate\Contracts\Console\Kernel, not a Mockery partial mock of the
     * live instance: Mockery::mock($existingObject)->makePartial() builds a
     * fresh, separately-constructed subclass instance whose own protected
     * properties (e.g. the kernel's $app) are never populated from the real
     * $existingObject, so any real method that touches that state (here,
     * getArtisan()'s use of $this->app) fails with a null-property error the
     * moment ->passthru() delegates to it. Composition avoids that
     * entirely: every call, mocked or not, delegates to the SAME real,
     * fully-constructed kernel instance.
     */
    public function test_txn_pk_integrity_report_is_invoked_via_artisan_call_not_reimplemented(): void
    {
        $realKernel = $this->app->make(ConsoleKernel::class);

        $spy = new class($realKernel) implements ConsoleKernel
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private ConsoleKernel $inner) {}

            public function bootstrap()
            {
                return $this->inner->bootstrap();
            }

            public function handle($input, $output = null)
            {
                return $this->inner->handle($input, $output);
            }

            public function call($command, array $parameters = [], $outputBuffer = null)
            {
                $this->calls[] = $command;

                return $this->inner->call($command, $parameters, $outputBuffer);
            }

            public function queue($command, array $parameters = [])
            {
                return $this->inner->queue($command, $parameters);
            }

            public function all()
            {
                return $this->inner->all();
            }

            public function output()
            {
                return $this->inner->output();
            }

            public function terminate($input, $status)
            {
                return $this->inner->terminate($input, $status);
            }
        };

        Artisan::swap($spy);

        $result = $this->runCommand([
            '--from' => '2030-04-01',
            '--to' => '2030-05-01',
        ]);

        $this->assertNotEmpty($result['integrity_report']);
        $this->assertContains('txn:pk-integrity', $spy->calls, 'txn:pk-integrity must be invoked via Artisan::call(), not reimplemented.');
        $this->assertContains(self::COMMAND, $spy->calls);
    }

    public function test_apply_persists_a_durably_queryable_row(): void
    {
        $result = $this->runCommand([
            '--from' => '2030-05-01',
            '--to' => '2030-06-01',
            '--apply' => true,
        ]);

        $this->assertSame('apply', $result['mode']);
        $this->assertTrue($result['persisted']);
        $this->assertNotNull($result['capture_id']);

        $capture = PreRunIntegrityCapture::find($result['capture_id']);

        $this->assertNotNull($capture, 'The persisted row must be independently queryable.');
        $this->assertSame(PreRunIntegrityCapture::PHASE_PRE_RUN, $capture->phase);
        $this->assertEquals(Carbon::parse('2030-05-01'), $capture->window_start);
        $this->assertEquals(Carbon::parse('2030-06-01'), $capture->window_end);
        // assertEquals, not assertSame: MySQL's JSON column type does not
        // guarantee preserving object key insertion order on round-trip, so
        // comparing the in-memory result against the value read back from
        // the DB must ignore key order while still requiring identical
        // key/value content.
        $this->assertEquals($result['duplicate_check_summary'], $capture->duplicate_check_summary);
        $this->assertSame($result['integrity_report'], $capture->integrity_report);
        $this->assertNotNull($capture->captured_at);
    }

    public function test_multiple_apply_invocations_for_the_same_window_coexist(): void
    {
        $first = $this->runCommand([
            '--from' => '2030-06-01',
            '--to' => '2030-07-01',
            '--apply' => true,
        ]);

        $second = $this->runCommand([
            '--from' => '2030-06-01',
            '--to' => '2030-07-01',
            '--apply' => true,
        ]);

        $this->assertNotSame($first['capture_id'], $second['capture_id'], 'Two --apply invocations for the same window must never overwrite or refuse — both must persist as distinct rows.');

        $count = PreRunIntegrityCapture::query()
            ->where('window_start', Carbon::parse('2030-06-01'))
            ->where('window_end', Carbon::parse('2030-07-01'))
            ->whereIn('id', [$first['capture_id'], $second['capture_id']])
            ->count();

        $this->assertSame(2, $count);
    }

    public function test_dry_run_runs_both_checks_but_writes_nothing(): void
    {
        $capturesBefore = PreRunIntegrityCapture::count();

        $result = $this->runCommand([
            '--from' => '2030-07-01',
            '--to' => '2030-08-01',
        ]);

        $this->assertSame('dry-run', $result['mode']);
        $this->assertFalse($result['persisted']);
        $this->assertNull($result['capture_id']);
        $this->assertArrayHasKey('total_duplicate_groups', $result['duplicate_check_summary']);
        $this->assertNotEmpty($result['integrity_report'], 'Dry-run must still run and display both checks.');

        $this->assertSame($capturesBefore, PreRunIntegrityCapture::count(), 'Dry-run must write zero rows.');
    }

    public function test_command_never_mutates_transaction_taxes_or_transactions(): void
    {
        $transaction = Transaction::factory()->create();
        $this->insertedTransactionIds[] = $transaction->id;
        $this->insertOrphanRow();
        $this->insertLinkedRow($transaction->id, 'VAT');

        $taxesBefore = DB::table('transaction_taxes')->count();
        $transactionsBefore = DB::table('transactions')->count();

        $this->runCommand([
            '--from' => '2030-08-01',
            '--to' => '2030-09-01',
        ]);

        $this->runCommand([
            '--from' => '2030-08-01',
            '--to' => '2030-09-01',
            '--apply' => true,
        ]);

        $this->assertSame($taxesBefore, DB::table('transaction_taxes')->count());
        $this->assertSame($transactionsBefore, DB::table('transactions')->count());
    }

    public function test_from_and_to_are_required(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--to' => '2030-01-01']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--from is required', Artisan::output());

        $exit = Artisan::call(self::COMMAND, ['--from' => '2030-01-01']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--to is required', Artisan::output());
    }

    public function test_json_and_human_output_parity(): void
    {
        // Both calls capture their own BufferedOutput explicitly — see
        // runCommand()'s docblock for why Artisan::output() is unreliable
        // for this command (its nested Artisan::call('txn:pk-integrity')
        // clobbers the Application's shared "last output" bookkeeping).
        $jsonOutput = new BufferedOutput;
        Artisan::call(self::COMMAND, [
            '--from' => '2030-09-01',
            '--to' => '2030-10-01',
            '--json' => true,
        ], $jsonOutput);
        $jsonResult = json_decode($jsonOutput->fetch(), true);

        $humanBuffer = new BufferedOutput;
        Artisan::call(self::COMMAND, [
            '--from' => '2030-09-01',
            '--to' => '2030-10-01',
        ], $humanBuffer);
        $humanOutput = $humanBuffer->fetch();

        $this->assertStringContainsString(
            (string) $jsonResult['duplicate_check_summary']['total_duplicate_groups'],
            $humanOutput
        );
        $this->assertStringContainsString($jsonResult['phase'], $humanOutput);
        $this->assertStringContainsString('Transaction PK integrity report', $humanOutput);
    }
}
