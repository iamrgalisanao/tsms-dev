<?php

namespace Tests\Feature\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Services\Backfill\IdleTransactionWatchdog;
use App\Services\Backfill\TaxBackfillOutcome;
use App\Services\Backfill\TaxBackfillPreflightChecker;
use App\Services\Backfill\TaxBackfillReasonCode;
use App\Services\Backfill\TaxBackfillRunner;
use App\Services\Backfill\TaxReconstructionService;
use App\Services\DeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\Support\DeadlockOnceOnMatchStatement;
use Tests\TestCase;
use Tests\Traits\FakesMetricsDistributionRedis;

/**
 * 002-backfill-transaction-taxes, Slice 3 (T016).
 *
 * Covers TaxBackfillRunner::dryRun() — wiring Slice 1's TaxReconstructionService
 * and Slice 2's audit persistence layer together with correct chunking and
 * classification. Dry-run only: no --apply path, no CLI, exists here yet.
 */
class TaxBackfillRunnerTest extends TestCase
{
    use FakesMetricsDistributionRedis;

    private function makeTransaction(Tenant $tenant, array $attributes = []): Transaction
    {
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return Transaction::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ], $attributes));
    }

    private function runner(
        ?TaxReconstructionService $service = null,
        ?TaxBackfillPreflightChecker $preflightChecker = null,
        ?IdleTransactionWatchdog $idleTransactionWatchdog = null
    ): TaxBackfillRunner {
        return new TaxBackfillRunner(
            $service ?? new TaxReconstructionService,
            new DeadlockRetryService,
            $preflightChecker ?? new TaxBackfillPreflightChecker,
            $idleTransactionWatchdog ?? new IdleTransactionWatchdog
        );
    }

    public function test_classifies_mixed_batch_into_correct_outcomes_and_updates_run_counters(): void
    {
        $tenant = Tenant::factory()->create();

        // (a) Already has a linked transaction_taxes row -> skipped_existing.
        $skipped = $this->makeTransaction($tenant);
        TransactionTax::create([
            'transaction_pk' => $skipped->id,
            'tax_type' => 'VAT',
            'amount' => 100.00,
        ]);

        // (b) Well-formed payload, cross-check agrees -> applied.
        $applied = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 120.00],
                ],
            ]),
            'vat_amount' => 120.00,
        ]);

        // (c) No recoverable payload -> quarantined / missing_payload.
        $missingPayload = $this->makeTransaction($tenant, [
            'original_payload' => null,
        ]);

        // (d) Payload reconstructs, but disagrees with stored columns -> quarantined / cross_check_mismatch.
        $mismatch = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 120.00],
                ],
            ]),
            'vat_amount' => 999.00,
        ]);

        // (e) Reconstruction blows up unexpectedly -> failed.
        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 50.00],
                ],
            ]),
        ]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing) {
                if ($transaction->id === $failing->id) {
                    throw new \RuntimeException('Simulated reconstruction failure');
                }

                return $real->reconstructTaxRows($transaction);
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        // Scoped to $tenant->id: this shared test database is not guaranteed
        // empty of unrelated transactions rows from other tests, so counts
        // below must reflect only what this test itself created.
        $run = $this->runner($mock)->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

        $this->assertSame(TaxBackfillRun::MODE_DRY_RUN, $run->mode);
        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(5, $run->scanned_count);
        $this->assertSame(1, $run->reconstructed_count);
        $this->assertSame(1, $run->skipped_existing_count);
        $this->assertSame(2, $run->quarantined_count);
        $this->assertSame(1, $run->failed_count);

        $record = fn (Transaction $t) => TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $t->id)->firstOrFail();

        $skippedRecord = $record($skipped);
        $this->assertSame(TaxBackfillOutcome::SkippedExisting->value, $skippedRecord->outcome);
        $this->assertNull($skippedRecord->reason_code);
        $this->assertTrue($skippedRecord->had_linked_rows_before);

        $appliedRecord = $record($applied);
        $this->assertSame(TaxBackfillOutcome::Applied->value, $appliedRecord->outcome);
        $this->assertNull($appliedRecord->reason_code);
        $this->assertFalse($appliedRecord->had_linked_rows_before);
        $this->assertEquals([['tax_type' => 'VAT', 'amount' => 120.00]], $appliedRecord->reconstructed_tax_rows);

        $missingRecord = $record($missingPayload);
        $this->assertSame(TaxBackfillOutcome::Quarantined->value, $missingRecord->outcome);
        $this->assertSame(TaxBackfillReasonCode::MissingPayload->value, $missingRecord->reason_code);
        $this->assertSame([], $missingRecord->reconstructed_tax_rows);

        $mismatchRecord = $record($mismatch);
        $this->assertSame(TaxBackfillOutcome::Quarantined->value, $mismatchRecord->outcome);
        $this->assertSame(TaxBackfillReasonCode::CrossCheckMismatch->value, $mismatchRecord->reason_code);
        $this->assertNotEmpty($mismatchRecord->reconstructed_tax_rows);

        $failedRecord = $record($failing);
        $this->assertSame(TaxBackfillOutcome::Failed->value, $failedRecord->outcome);
        $this->assertStringContainsString('RuntimeException', $failedRecord->reason_code);
        $this->assertStringContainsString('Simulated reconstruction failure', $failedRecord->reason_code);
    }

    public function test_dry_run_never_writes_to_transaction_taxes_for_any_classification(): void
    {
        $tenant = Tenant::factory()->create();

        $skipped = $this->makeTransaction($tenant);
        TransactionTax::create([
            'transaction_pk' => $skipped->id,
            'tax_type' => 'VAT',
            'amount' => 100.00,
        ]);

        $applied = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 42.00]]]),
            'vat_amount' => 42.00,
        ]);

        $missingPayload = $this->makeTransaction($tenant, ['original_payload' => null]);

        $mismatch = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 42.00]]]),
            'vat_amount' => 1.00,
        ]);

        $beforeCount = DB::table('transaction_taxes')->count();

        $this->runner()->dryRun(now()->subDay(), now()->addDay());

        // No new rows appear for the reconstructable/quarantined transactions
        // regardless of outcome — the pre-existing linked row for $skipped is
        // the only real transaction_taxes row that should exist afterward.
        $this->assertSame($beforeCount, DB::table('transaction_taxes')->count());
        $this->assertSame(0, DB::table('transaction_taxes')
            ->whereIn('transaction_pk', [$applied->id, $missingPayload->id, $mismatch->id])
            ->count());
    }

    public function test_chunking_processes_all_transactions_exactly_once_across_multiple_chunks(): void
    {
        $tenant = Tenant::factory()->create();

        $transactions = collect(range(1, 5))->map(
            fn () => $this->makeTransaction($tenant, ['original_payload' => null])
        );

        $run = $this->runner()->dryRun(now()->subDay(), now()->addDay(), $tenant->id, null, chunkSize: 2);

        $this->assertSame(5, $run->scanned_count);
        $this->assertSame(5, $run->quarantined_count);

        $recordedIds = TaxBackfillRecord::where('run_id', $run->id)->pluck('transaction_pk')->sort()->values();
        $this->assertSame($transactions->pluck('id')->sort()->values()->all(), $recordedIds->all());

        // No duplicate records for any transaction across the multiple chunks.
        $this->assertSame(5, TaxBackfillRecord::where('run_id', $run->id)->count());
    }

    public function test_a_single_failing_transaction_does_not_prevent_others_in_the_same_run_from_being_recorded(): void
    {
        $tenant = Tenant::factory()->create();

        $before = $this->makeTransaction($tenant, ['original_payload' => null]);
        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 10.00]]]),
        ]);
        $after = $this->makeTransaction($tenant, ['original_payload' => null]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing) {
                if ($transaction->id === $failing->id) {
                    throw new \RuntimeException('boom');
                }

                return $real->reconstructTaxRows($transaction);
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        $run = $this->runner($mock)->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

        $this->assertSame(3, $run->scanned_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(2, $run->quarantined_count);

        $this->assertSame(
            TaxBackfillOutcome::Failed->value,
            TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $failing->id)->value('outcome')
        );
        $this->assertSame(
            TaxBackfillOutcome::Quarantined->value,
            TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $before->id)->value('outcome')
        );
        $this->assertSame(
            TaxBackfillOutcome::Quarantined->value,
            TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $after->id)->value('outcome')
        );
    }

    /**
     * Code review finding: the original implementation's catch-block
     * recordOutcome() call (for the `failed` outcome) was itself
     * unprotected. If *that* write also throws — a genuine DB-level error,
     * not merely a reconstruction failure — the exception used to escape
     * processTransaction(), roll back the whole chunk's DB::transaction()
     * (losing every already-recorded row in that chunk, not just the bad
     * one), and leave the run stuck at status = 'running' forever.
     *
     * This forces a *real* QueryException (an actual FK violation on
     * tax_backfill_records.transaction_pk), not a mocked one: the
     * "reconstruction" step deletes the underlying transactions row for one
     * transaction as a side effect (simulating a concurrent hard-delete),
     * so every later attempt to INSERT a TaxBackfillRecord referencing that
     * transaction_pk — both the original outcome's insert and the
     * catch-block's `failed`-outcome insert — genuinely fails at the
     * database level.
     */
    public function test_a_genuine_db_failure_recording_an_outcome_does_not_roll_back_the_rest_of_the_chunk(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create();

        $before = $this->makeTransaction($tenant, ['original_payload' => null]);
        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 10.00]]]),
            'vat_amount' => 10.00,
        ]);
        $after = $this->makeTransaction($tenant, ['original_payload' => null]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing) {
                $rows = $real->reconstructTaxRows($transaction);

                if ($transaction->id === $failing->id) {
                    // Simulate the transaction row disappearing mid-scan
                    // (e.g. a concurrent hard-delete elsewhere) so the later
                    // TaxBackfillRecord insert(s) for it genuinely violate
                    // transaction_pk's real FK constraint — not a stubbed
                    // exception.
                    DB::table('transactions')->where('id', $failing->id)->delete();
                }

                return $rows;
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        // All three land in the same (default-sized) chunk.
        $run = $this->runner($mock)->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

        // The run finished the scan (it did not throw out to the caller —
        // the double failure is contained and reported via run status, not
        // an exception) but must not be reported as a clean 'completed' run
        // now that at least one transaction's outcome genuinely could not
        // be recorded.
        $this->assertSame(TaxBackfillRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->completed_at);

        // The chunk's OTHER transactions survive — the one unrecordable row
        // did not roll back the whole chunk's DB transaction.
        $this->assertDatabaseHas('tax_backfill_records', [
            'run_id' => $run->id,
            'transaction_pk' => $before->id,
        ]);
        $this->assertDatabaseHas('tax_backfill_records', [
            'run_id' => $run->id,
            'transaction_pk' => $after->id,
        ]);

        // No TaxBackfillRecord exists for the failing transaction — both
        // write attempts genuinely failed, so nothing was fabricated.
        $this->assertDatabaseMissing('tax_backfill_records', [
            'run_id' => $run->id,
            'transaction_pk' => $failing->id,
        ]);

        // scanned_count/failed_count still reflect that the transaction was
        // processed and classified as failed, even though its audit row
        // could not be persisted.
        $this->assertSame(3, $run->scanned_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(2, $run->quarantined_count);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($failing) {
                return str_contains($message, 'could not record a failed-outcome audit row')
                    && $context['transaction_id'] === $failing->id;
            });
    }

    /**
     * Slice 4 (T017 drift note): cli-contract.md specifies `--tenant` as
     * repeatable, so dryRun() must accept a list of tenant ids and restrict
     * to exactly those tenants — not just the single-int / null cases
     * already covered above.
     */
    public function test_dry_run_restricts_to_exactly_the_given_list_of_tenant_ids(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $tenantC = Tenant::factory()->create();

        $inA = $this->makeTransaction($tenantA, ['original_payload' => null]);
        $inB = $this->makeTransaction($tenantB, ['original_payload' => null]);
        $inC = $this->makeTransaction($tenantC, ['original_payload' => null]);

        $run = $this->runner()->dryRun(now()->subDay(), now()->addDay(), [$tenantA->id, $tenantB->id]);

        $this->assertSame(2, $run->scanned_count);

        $recordedTransactionIds = TaxBackfillRecord::where('run_id', $run->id)->pluck('transaction_pk')->sort()->values()->all();
        $this->assertSame([$inA->id, $inB->id], $recordedTransactionIds);
        $this->assertFalse(in_array($inC->id, $recordedTransactionIds, true));
    }

    public function test_run_status_and_timestamps_end_in_expected_state_after_a_successful_dry_run(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, ['original_payload' => null]);

        $from = now()->subDay();
        $to = now()->addDay();

        $run = $this->runner()->dryRun($from, $to, $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
        $this->assertTrue($run->completed_at->gte($run->started_at));
        $this->assertSame(TaxBackfillRun::MODE_DRY_RUN, $run->mode);
        // Compared at second precision: the `datetime` cast round-trips
        // through a DB column with no microsecond storage.
        $this->assertSame($from->format('Y-m-d H:i:s'), $run->window_start->format('Y-m-d H:i:s'));
        $this->assertSame($to->format('Y-m-d H:i:s'), $run->window_end->format('Y-m-d H:i:s'));
    }

    /**
     * Slice 6 (T026-T029): the first test in this feature that asserts a
     * real `transaction_taxes` row was written. Covers the baseline
     * "otherwise" branch of apply()'s classification: zero linked rows,
     * valid payload, clean cross-check.
     */
    public function test_apply_inserts_reconstructed_tax_rows_using_the_parent_transactions_created_at(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 120.00],
                ],
            ]),
            'vat_amount' => 120.00,
        ]);

        // Force created_at to a value distinguishable from "the wall-clock
        // time this test happened to run" — using $backdated as both the
        // transaction's own created_at AND the $day passed to apply() below
        // guarantees the fixture falls within the scanned day regardless of
        // when the test suite actually executes.
        $backdated = $transaction->created_at->copy()->subHours(3);
        $transaction->forceFill(['created_at' => $backdated])->save();
        $transaction->refresh();

        $run = $this->runner()->apply($backdated, $tenant->id);

        $this->assertSame(TaxBackfillRun::MODE_APPLY, $run->mode);
        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->reconstructed_count);

        $taxRow = DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->first();
        $this->assertNotNull($taxRow);
        $this->assertSame('VAT', $taxRow->tax_type);
        $this->assertEquals(120.00, $taxRow->amount);
        $this->assertSame(
            $backdated->format('Y-m-d H:i:s'),
            Carbon::parse($taxRow->created_at)->format('Y-m-d H:i:s')
        );
        $this->assertNotSame(
            now()->format('Y-m-d H:i:s'),
            Carbon::parse($taxRow->created_at)->format('Y-m-d H:i:s')
        );

        $record = TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $transaction->id)->firstOrFail();
        $this->assertSame(TaxBackfillOutcome::Applied->value, $record->outcome);
        $this->assertNull($record->reason_code);
        $this->assertFalse($record->had_linked_rows_before);
    }

    /**
     * T034: re-running apply() over the same day must converge every
     * previously-applied transaction to skipped_existing on the second pass,
     * with zero duplicate transaction_taxes rows. Counts are scoped to this
     * test's own fixture id per the known RefreshDatabase isolation bug
     * (tasks.md Backlog), never table-wide.
     */
    public function test_apply_run_twice_over_the_same_day_converges_to_skipped_existing_with_zero_duplicates(): void
    {
        $tenant = Tenant::factory()->create();

        $applied = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 55.00],
                ],
            ]),
            'vat_amount' => 55.00,
        ]);

        $day = $applied->created_at->copy();

        $firstRun = $this->runner()->apply($day, $tenant->id);
        $this->assertSame(1, $firstRun->reconstructed_count);
        $this->assertSame(0, $firstRun->skipped_existing_count);
        $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $applied->id)->count());

        $secondRun = $this->runner()->apply($day, $tenant->id);
        $this->assertSame(0, $secondRun->reconstructed_count);
        $this->assertSame(1, $secondRun->skipped_existing_count);

        // Zero duplicate rows for this test's own fixture id.
        $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $applied->id)->count());

        $secondRecord = TaxBackfillRecord::where('run_id', $secondRun->id)->where('transaction_pk', $applied->id)->firstOrFail();
        $this->assertSame(TaxBackfillOutcome::SkippedExisting->value, $secondRecord->outcome);
        $this->assertNull($secondRecord->reason_code);
        $this->assertTrue($secondRecord->had_linked_rows_before);
    }

    /**
     * T035: a transaction with a pre-existing linked transaction_taxes row
     * (simulating a prior manual correction) whose amount deliberately would
     * NOT match what reconstruction would produce is left completely
     * untouched — no insert, and (via the Mockery expectations below)
     * reconstruction/cross-check are never even invoked for it.
     */
    public function test_apply_never_touches_a_transaction_with_a_pre_existing_linked_tax_row(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 75.00],
                ],
            ]),
            'vat_amount' => 75.00,
        ]);

        // Pre-existing linked row simulating a prior manual correction, with
        // an amount that would NOT match what reconstruction would produce.
        TransactionTax::create([
            'transaction_pk' => $transaction->id,
            'tax_type' => 'VAT',
            'amount' => 1.23,
        ]);

        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldNotReceive('reconstructTaxRows');
        $mock->shouldNotReceive('crossCheck');

        $run = $this->runner($mock)->apply($transaction->created_at->copy(), $tenant->id);

        $this->assertSame(1, $run->skipped_existing_count);
        $this->assertSame(0, $run->reconstructed_count);
        $this->assertSame(0, $run->quarantined_count);
        $this->assertSame(0, $run->failed_count);

        $rows = DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(1.23, $rows->first()->amount);
        $this->assertSame('VAT', $rows->first()->tax_type);
    }

    /**
     * Quarantined (missing payload / cross-check mismatch) and failed
     * transactions must never produce a transaction_taxes row during
     * apply() — only the `applied` classification ever inserts.
     */
    public function test_apply_never_inserts_tax_rows_for_quarantined_or_failed_transactions(): void
    {
        $tenant = Tenant::factory()->create();

        $missingPayload = $this->makeTransaction($tenant, ['original_payload' => null]);

        $mismatch = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 40.00],
                ],
            ]),
            'vat_amount' => 999.00,
        ]);

        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 10.00],
                ],
            ]),
        ]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing) {
                if ($transaction->id === $failing->id) {
                    throw new \RuntimeException('Simulated reconstruction failure');
                }

                return $real->reconstructTaxRows($transaction);
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        $run = $this->runner($mock)->apply(now(), $tenant->id);

        $this->assertSame(3, $run->scanned_count);
        $this->assertSame(0, $run->reconstructed_count);
        $this->assertSame(2, $run->quarantined_count);
        $this->assertSame(1, $run->failed_count);

        $this->assertSame(0, DB::table('transaction_taxes')
            ->whereIn('transaction_pk', [$missingPayload->id, $mismatch->id, $failing->id])
            ->count());
    }

    /**
     * Code review finding 2 (Slice 6): this test's original name/docblock
     * claimed to prove per-transaction *commit-boundary* isolation ("one
     * transaction's DB failure can't roll back another's already-committed
     * insert"). It does NOT actually prove that — processTransactionForApply()
     * already catches everything internally under normal failure modes and
     * never lets an exception reach apply()'s chunk-loop try/catch, so
     * wrapping the whole chunk in one DB::transaction() (the exact
     * regression the architecture brief warns against) would still pass
     * this test unchanged. What this test DOES genuinely prove is
     * per-transaction *outcome* isolation: a transaction whose real
     * transaction_taxes insert genuinely fails at the DB level (forced the
     * same way Slice 3's fix test forces it — the "reconstruction" step
     * deletes the underlying `transactions` row as a side effect, so both
     * the transaction_taxes insert (fk_tx_taxes_pk) and the failed-outcome
     * TaxBackfillRecord insert genuinely violate a real FK constraint,
     * independent of the `strict` SQL mode setting which is false for the
     * `mysql` connection) does not corrupt the classification/recording of
     * the other transactions scanned in the same run, and the run still
     * reaches a terminal status rather than staying `running` forever.
     *
     * See test_apply_preserves_an_earlier_transactions_committed_work_when_a_later_transaction_in_the_same_chunk_escapes_both_containment_layers()
     * below for the test that actually exercises the per-transaction commit
     * boundary this one does not.
     */
    public function test_a_genuine_db_level_insert_failure_for_one_transaction_does_not_corrupt_the_recording_of_others(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create();

        $before = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 30.00]]]),
            'vat_amount' => 30.00,
        ]);
        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 10.00]]]),
            'vat_amount' => 10.00,
        ]);
        $after = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 60.00]]]),
            'vat_amount' => 60.00,
        ]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing) {
                $rows = $real->reconstructTaxRows($transaction);

                if ($transaction->id === $failing->id) {
                    // Simulate the transaction row disappearing mid-scan
                    // (e.g. a concurrent hard-delete elsewhere) so the later
                    // transaction_taxes insert for it genuinely violates its
                    // real FK constraint on transaction_pk -> transactions.id
                    // — not a stubbed exception.
                    DB::table('transactions')->where('id', $failing->id)->delete();
                }

                return $rows;
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        // All three land in the same (default-sized) chunk.
        $run = $this->runner($mock)->apply(now(), $tenant->id);

        // The double failure (real insert fails, then the failed-outcome
        // audit write also fails against the same missing parent row) is
        // contained and reported via run status/counters, not an exception
        // escaping to the caller — but it is NOT a clean 'completed' run.
        $this->assertSame(TaxBackfillRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(3, $run->scanned_count);
        $this->assertSame(2, $run->reconstructed_count);
        $this->assertSame(0, $run->quarantined_count);
        // failed_count stays 0, not 1: the increment lives inside the same
        // transaction as the failed-outcome TaxBackfillRecord write (code
        // review finding 1's fix), and that write is exactly what failed
        // here too — so the increment never committed either. Counting it
        // anyway would double-count against whatever later corrects this
        // transaction.
        $this->assertSame(0, $run->failed_count);

        // The other two transactions' inserts genuinely committed and
        // survive untouched — each transaction's insert lives in its own
        // short DB transaction, so the failing one's failure/rollback
        // cannot reach them.
        $this->assertDatabaseHas('transaction_taxes', ['transaction_pk' => $before->id, 'amount' => 30.00]);
        $this->assertDatabaseHas('transaction_taxes', ['transaction_pk' => $after->id, 'amount' => 60.00]);

        // The failing transaction genuinely has no tax row and no audit
        // record at all — both write attempts failed at the DB level, so
        // nothing was fabricated.
        $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $failing->id)->count());
        $this->assertDatabaseMissing('tax_backfill_records', [
            'run_id' => $run->id,
            'transaction_pk' => $failing->id,
        ]);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($failing) {
                return str_contains($message, 'could not record a failed-outcome audit row for a transaction during apply')
                    && $context['transaction_id'] === $failing->id;
            });
    }

    /**
     * Code review finding 1 (Slice 6, critical): reproduces the reviewer's
     * exact technique — forcing a genuine DB-level failure specifically on
     * the `$run->increment(...)` statement via DB::beforeExecuting(), not a
     * mocked exception — to prove the fix. Before the fix, every
     * `increment()` call sat AFTER its corresponding
     * DeadlockRetryService/DB::transaction() closure returned, so a failure
     * there left the closure's work (the tax-row insert + the `applied`
     * TaxBackfillRecord) already committed while control fell through to
     * the outer catch, which then wrote a SECOND, contradictory `failed`
     * TaxBackfillRecord for the same transaction and double-counted run
     * totals. With the fix, the increment lives inside the same
     * transaction as the audit write (and, for `applied`, the tax-row
     * insert) it belongs to, so a failure there rolls the whole unit back
     * atomically — no tax row, no `applied` record, and the outer catch
     * then records a single, consistent `failed` outcome against a
     * transaction with zero prior state.
     */
    public function test_a_db_failure_on_the_counter_increment_does_not_leave_two_contradictory_outcome_records(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 88.00]]]),
            'vat_amount' => 88.00,
        ]);

        $day = $transaction->created_at->copy();

        $triggered = false;
        DB::beforeExecuting(function (string $query) use (&$triggered) {
            $isReconstructedCountIncrement = str_starts_with(strtolower(trim($query)), 'update ')
                && str_contains($query, 'tax_backfill_runs')
                && str_contains($query, 'reconstructed_count');

            if (! $triggered && $isReconstructedCountIncrement) {
                $triggered = true;

                throw new \RuntimeException('Simulated DB failure on the reconstructed_count increment.');
            }
        });

        $run = $this->runner()->apply($day, $tenant->id);

        $this->assertTrue($triggered, 'The forced failure never actually triggered — this test would otherwise pass vacuously.');

        // Exactly one TaxBackfillRecord for this transaction, ever — never
        // two contradictory rows (one applied, one failed) for the same
        // transaction, which is precisely the bug this test guards against.
        $records = TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $transaction->id)->get();
        $this->assertCount(1, $records);
        $this->assertSame(TaxBackfillOutcome::Failed->value, $records->first()->outcome);

        // The tax-row insert rolled back along with the failed increment —
        // insert + audit write + increment are one atomic unit.
        $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());

        // Run-level counters are not double-counted: reconstructed_count
        // never committed (its own increment is exactly what failed), and
        // failed_count reflects the single genuinely-recorded `failed`
        // outcome — not incremented twice, not left at zero either.
        //
        // Eloquent's increment() optimistically mutates the in-memory
        // attribute before issuing the query (Model::incrementOrDecrement()),
        // so $run's in-memory reconstructed_count would still read 1 here
        // even though the DB row rolled back — refresh() re-reads the
        // actually-persisted values.
        $run->refresh();
        $this->assertSame(0, $run->reconstructed_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(1, $run->scanned_count);
    }

    /**
     * Code review finding 2 (Slice 6): the test above
     * (test_a_genuine_db_level_insert_failure_for_one_transaction_does_not_corrupt_the_recording_of_others)
     * proves per-transaction *outcome* isolation but not the per-transaction
     * *commit-boundary* property the architecture brief actually requires —
     * the reviewer verified that wrapping apply()'s whole chunk in a single
     * DB::transaction() (dryRun()'s pattern, and exactly the regression the
     * brief warns against) still passes every prior test unchanged, because
     * processTransactionForApply() already absorbs ordinary failures
     * internally and never lets them reach apply()'s own chunk-loop
     * try/catch.
     *
     * The ONE statement in processTransactionForApply() with no
     * surrounding try/catch of its own is the `scanned_count` increment in
     * its `finally` block (deliberate, mirroring dryRun(): it must still
     * increment even when everything else about a transaction failed). This
     * test forces a genuine DB-level failure there — via the same
     * DB::beforeExecuting() technique as finding 1's test, targeting the
     * SECOND transaction's `scanned_count` update specifically — which is
     * the only way to make an exception genuinely escape both containment
     * layers and reach apply()'s outer catch in this design.
     *
     * By the time that escape happens, the second transaction's own real
     * work (tax-row insert, `applied` record, `reconstructed_count`
     * increment) has ALREADY committed in its own independent top-level
     * transaction — as has the first transaction's, entirely. Under the
     * correct per-transaction design, both are therefore durable no matter
     * what happens next. Under the chunk-wide mutation the reviewer tested,
     * every transaction's writes in the chunk are nested savepoints inside
     * one still-open outer transaction; when the escape unwinds that outer
     * transaction, BOTH transactions' work would be rolled back with it —
     * which is exactly the regression this test would catch that the test
     * above cannot.
     */
    public function test_apply_preserves_an_earlier_transactions_committed_work_when_a_later_transaction_in_the_same_chunk_escapes_both_containment_layers(): void
    {
        $tenant = Tenant::factory()->create();

        $first = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 22.00]]]),
            'vat_amount' => 22.00,
        ]);
        $second = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 33.00]]]),
            'vat_amount' => 33.00,
        ]);

        $day = $first->created_at->copy();
        $maxRunIdBefore = TaxBackfillRun::max('id') ?? 0;

        $scannedCountUpdates = 0;
        DB::beforeExecuting(function (string $query) use (&$scannedCountUpdates) {
            $isScannedCountIncrement = str_starts_with(strtolower(trim($query)), 'update ')
                && str_contains($query, 'tax_backfill_runs')
                && str_contains($query, 'scanned_count');

            if (! $isScannedCountIncrement) {
                return;
            }

            $scannedCountUpdates++;

            // Let the first transaction's own scanned_count increment
            // succeed normally; break the second one, so both transactions'
            // core work has already been attempted (and, for a durable
            // per-transaction design, committed) by the time the escape
            // happens.
            if ($scannedCountUpdates === 2) {
                throw new \RuntimeException('Simulated DB failure escaping both containment layers.');
            }
        });

        $runner = $this->runner();

        try {
            $runner->apply($day, $tenant->id, chunkSize: 2);
            $this->fail('Expected apply() to rethrow the exception that escaped both containment layers.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated DB failure escaping both containment layers.', $e->getMessage());
        }

        $run = TaxBackfillRun::where('id', '>', $maxRunIdBefore)->firstOrFail();

        $this->assertSame(TaxBackfillRun::STATUS_INTERRUPTED, $run->status);
        $this->assertNotNull($run->completed_at);

        // Both transactions' inserts and `applied` audit records survive —
        // each committed independently in its own top-level transaction
        // before the escape occurred, so the later crash cannot reach back
        // and undo either of them.
        $this->assertDatabaseHas('transaction_taxes', ['transaction_pk' => $first->id, 'amount' => 22.00]);
        $this->assertDatabaseHas('transaction_taxes', ['transaction_pk' => $second->id, 'amount' => 33.00]);

        $firstRecord = TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $first->id)->first();
        $this->assertNotNull($firstRecord);
        $this->assertSame(TaxBackfillOutcome::Applied->value, $firstRecord->outcome);

        $secondRecord = TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $second->id)->first();
        $this->assertNotNull($secondRecord);
        $this->assertSame(TaxBackfillOutcome::Applied->value, $secondRecord->outcome);

        // reconstructed_count reflects both committed applications;
        // scanned_count reflects only the first transaction's own
        // successful bookkeeping increment — the second one is exactly the
        // statement that threw.
        $this->assertSame(2, $run->reconstructed_count);
        $this->assertSame(1, $run->scanned_count);
    }

    /**
     * Chunking-proof pattern reused from Slice 3's dryRun() coverage:
     * multiple transactions across multiple chunks must all be processed
     * (and, for apply(), actually written) exactly once.
     */
    public function test_apply_processes_all_transactions_exactly_once_across_multiple_chunks(): void
    {
        $tenant = Tenant::factory()->create();

        $transactions = collect();
        for ($n = 1; $n <= 5; $n++) {
            $transactions->push($this->makeTransaction($tenant, [
                'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => $n * 10.00]]]),
                'vat_amount' => $n * 10.00,
            ]));
        }

        $run = $this->runner()->apply(now(), $tenant->id, null, chunkSize: 2);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(5, $run->scanned_count);
        $this->assertSame(5, $run->reconstructed_count);

        $recordedIds = TaxBackfillRecord::where('run_id', $run->id)->pluck('transaction_pk')->sort()->values();
        $this->assertSame($transactions->pluck('id')->sort()->values()->all(), $recordedIds->all());
        $this->assertSame(5, TaxBackfillRecord::where('run_id', $run->id)->count());

        foreach ($transactions as $index => $transaction) {
            $this->assertDatabaseHas('transaction_taxes', [
                'transaction_pk' => $transaction->id,
                'amount' => ($index + 1) * 10.00,
            ]);
        }
    }

    /**
     * Defensive-guard test (brief's own test plan): the transaction_pk
     * non-null assertion inside insertTaxRows() should be unreachable via
     * apply() itself (taxes()->exists() and everything upstream already
     * require a persisted transaction), but data-model.md flags
     * transaction_pk as nullable at the DB level — the exact nullability
     * behind the original 3.24M-row defect — so the guard itself must be
     * proven directly rather than merely assumed.
     */
    public function test_insert_tax_rows_refuses_to_insert_when_transaction_id_is_null(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = $this->makeTransaction($tenant);

        $beforeCount = DB::table('transaction_taxes')->count();

        $transaction->id = null;

        $runner = $this->runner();
        $method = new \ReflectionMethod($runner, 'insertTaxRows');
        $method->setAccessible(true);

        try {
            $method->invoke($runner, $transaction, [['tax_type' => 'VAT', 'amount' => 10.00]]);
            $this->fail('Expected insertTaxRows() to refuse a transaction with a null id.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('null id', $e->getMessage());
        }

        $this->assertSame($beforeCount, DB::table('transaction_taxes')->count());
    }

    /**
     * Regression test for the nested-transaction/DeadlockException bug fixed
     * alongside this test: every write site inside processTransactionForApply()
     * used to wrap its DeadlockRetryService::withDeadlockRetry() closure in a
     * *second*, redundant DB::transaction() — but withDeadlockRetry() already
     * opens one itself (it calls DB::transaction($callback, 1) internally).
     * With two transactions nested, Laravel's own
     * ManagesTransactions::handleTransactionException() treats a
     * deadlock/lock-wait caught while $this->transactions > 1 as
     * unrecoverable: it throws a DeadlockException (a PDOException subclass,
     * NOT a QueryException) instead of rolling back and letting the caller
     * retry. DeadlockRetryService::withDeadlockRetry()'s catch block is
     * `catch (QueryException $e)`, so it can never catch that
     * DeadlockException — the retry never engages and the transaction ends
     * up recorded as `failed` instead of `applied`.
     *
     * This test forces a *genuine* QueryException matching
     * isRetryableDeadlock()'s exact phrase ("Deadlock found when trying to
     * get lock") on the very first execution of the `transaction_taxes`
     * insert inside the `Applied` write path, via DeadlockOnceOnMatchStatement
     * (defined above) installed on the real PDO connection — deliberately
     * NOT a mocked DeadlockRetryService/TaxBackfillRunner, because the bug
     * lives specifically in Laravel's real transaction-depth bookkeeping,
     * which no amount of mocking those two classes could reproduce.
     *
     * RefreshDatabase already wraps this test in its own transaction before
     * this method runs, which by itself would push the connection's nesting
     * depth to >1 the moment ANY DB::transaction() opens inside apply() —
     * masking whether TaxBackfillRunner's own code adds a SECOND level on
     * top, i.e. exactly the distinction this test exists to prove. DB::commit()
     * below flushes that ambient wrapping transaction away (down to depth 0)
     * before exercising apply(), so the only transaction depth in play is
     * whatever apply()'s own write path creates. The corresponding `finally`
     * block below then manually cleans up the rows this test consequently
     * commits for real (RefreshDatabase's usual rollback can no longer do
     * it) and reopens an empty transaction so RefreshDatabase's teardown
     * rollback — and its "was the connection left mid-transaction?"
     * remigration check — behave exactly as they would for any other test.
     *
     * Cleanup precision note: DB::commit()-ing away RefreshDatabase's wrap
     * also makes permanent two side effects that would otherwise have been
     * silently rolled back for free: (1) TransactionFactory::definition()
     * unconditionally calls Tenant::factory()->create() and
     * PosTerminal::factory()->create() as throwaway defaults BEFORE this
     * test's own tenant_id/terminal_id overrides are applied — those rows
     * are created for real regardless of the override, orphaning a Company
     * (via TenantFactory's own nested Company::factory() default) + Tenant
     * + PosTerminal that nothing in this test otherwise references; and (2)
     * this test's own explicit Tenant::factory()->create() call similarly
     * creates a backing Company via TenantFactory's nested default. Neither
     * is identifiable by a fixed id captured up front (they're never
     * assigned to a variable), so companies/tenants/pos_terminals row ids
     * are snapshotted immediately after DB::commit() (before ANY fixture
     * creation) and diffed against the post-setup state — every id absent
     * from the snapshot, whether this test's own explicit fixtures or one
     * of the factory's throwaway side effects, is deleted in the `finally`
     * block below. This is deliberately id-set-based rather than a blanket
     * "delete everything in these tables," so it cannot touch a row another
     * concurrently-running test process created.
     */
    public function test_a_genuine_deadlock_on_the_apply_write_path_is_retried_and_the_second_attempt_succeeds(): void
    {
        config()->set('tsms.metrics.distribution.redis_connection', 'default');
        config()->set('tsms.metrics.distribution.key_prefix', 'metrics:dist:');
        config()->set('tsms.metrics.distribution.sample_cap', 1000);
        config()->set('tsms.metrics.distribution.sample_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.cardinality_budget', 200);
        config()->set('tsms.metrics.distribution.cardinality_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.allowed_dimensions', ['route', 'shard']);
        $this->fakeMetricsDistributionRedis();

        DB::commit();

        // Baseline snapshots, captured before any fixture in this test
        // exists, so cleanup below can precisely identify every row this
        // test's DB::commit() workaround caused to become permanent —
        // including factory side-effect rows never assigned to a variable
        // (see the docblock above). DB::table() bypasses Eloquent's
        // SoftDeletes global scope, so the tenants snapshot includes
        // already soft-deleted rows too, exactly matching what the later
        // diff query (also via DB::table()) will see.
        $companyIdsBefore = DB::table('companies')->pluck('id')->all();
        $tenantIdsBefore = DB::table('tenants')->pluck('id')->all();
        $terminalIdsBefore = DB::table('pos_terminals')->pluck('id')->all();

        $pdo = DB::connection()->getPdo();
        $defaultStatementClass = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);

        $transaction = null;
        $run = null;

        try {
            $tenant = Tenant::factory()->create();
            $transaction = $this->makeTransaction($tenant, [
                'original_payload' => json_encode([
                    'taxes' => [['tax_type' => 'VAT', 'amount' => 77.00]],
                ]),
                'vat_amount' => 77.00,
            ]);

            $day = $transaction->created_at->copy();

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DeadlockOnceOnMatchStatement::class, []]);
            DeadlockOnceOnMatchStatement::$matcher = fn (string $sql) => str_starts_with(strtolower(trim($sql)), 'insert')
                && str_contains($sql, 'transaction_taxes');
            DeadlockOnceOnMatchStatement::$remainingFailures = 1;

            $run = $this->runner()->apply($day, $tenant->id);

            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);

            $this->assertSame(
                1,
                DeadlockOnceOnMatchStatement::$timesTriggered,
                'The forced deadlock never actually triggered on the transaction_taxes insert — this test would otherwise pass vacuously.'
            );

            $this->assertSame(TaxBackfillRun::MODE_APPLY, $run->mode);
            $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
            $this->assertSame(1, $run->reconstructed_count);
            $this->assertSame(0, $run->failed_count);

            $taxRow = DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->first();
            $this->assertNotNull($taxRow, 'Expected the retried second attempt to have inserted the tax row.');
            $this->assertSame('VAT', $taxRow->tax_type);
            $this->assertEquals(77.00, $taxRow->amount);

            $record = TaxBackfillRecord::where('run_id', $run->id)->where('transaction_pk', $transaction->id)->firstOrFail();
            $this->assertSame(
                TaxBackfillOutcome::Applied->value,
                $record->outcome,
                'Expected the retried second attempt to converge on `applied`, not `failed`.'
            );
            $this->assertNull($record->reason_code);
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $defaultStatementClass);
            DeadlockOnceOnMatchStatement::reset();

            // Manual cleanup in FK-safe order (children before parents):
            // tax_backfill_records before tax_backfill_runs (no cascade —
            // see the migration) and before transactions (also no cascade
            // on transaction_pk); transactions before pos_terminals/tenants
            // (both RESTRICT); tenants before companies (RESTRICT). Every
            // delete goes through DB::table() rather than the Eloquent
            // models so this is a genuine hard delete regardless of
            // SoftDeletes (Tenant) — leaving zero footprint on a raw
            // COUNT(*), not just an Eloquent-invisible soft-deleted row.
            if ($run !== null) {
                DB::table('tax_backfill_records')->where('run_id', $run->id)->delete();
                DB::table('tax_backfill_runs')->where('id', $run->id)->delete();
            }
            if ($transaction !== null) {
                DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->delete();
                DB::table('transactions')->where('id', $transaction->id)->delete();
            }

            // Diff-based cleanup for every companies/tenants/pos_terminals
            // row that appeared since the pre-fixture snapshot above —
            // covers both this test's own explicit Tenant (+ its
            // TenantFactory-nested Company) and the wholly-unreferenced
            // orphan Company+Tenant+PosTerminal that
            // TransactionFactory::definition() creates as a side effect
            // regardless of the tenant_id/terminal_id overrides passed to
            // it (see the docblock above). pos_terminals first (references
            // tenants), then tenants (references companies), then
            // companies.
            DB::table('pos_terminals')->whereNotIn('id', $terminalIdsBefore)->delete();
            DB::table('tenants')->whereNotIn('id', $tenantIdsBefore)->delete();
            DB::table('companies')->whereNotIn('id', $companyIdsBefore)->delete();

            DB::beginTransaction();
        }
    }

    /**
     * Slice 7, T031: apply() with a small chunkSize (forcing multiple
     * chunks) and a non-null $throttleMs measurably delays — the sleep
     * happens once per completed chunk (chunk-granularity, never
     * per-transaction, per the architecture brief), so wall-clock elapsed
     * time must be at least (chunk_count - 1) * $throttleMs. Small values
     * throughout keep this fast.
     */
    public function test_apply_with_a_throttle_measurably_delays_between_chunks(): void
    {
        $tenant = Tenant::factory()->create();

        // 5 transactions, chunkSize 2 -> 3 chunks (2, 2, 1).
        for ($n = 1; $n <= 5; $n++) {
            $this->makeTransaction($tenant, ['original_payload' => null]);
        }

        $throttleMs = 50;
        $chunkCount = 3;

        $start = microtime(true);
        $run = $this->runner()->apply(now(), $tenant->id, null, chunkSize: 2, throttleMs: $throttleMs);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(5, $run->scanned_count);
        $this->assertGreaterThanOrEqual(
            ($chunkCount - 1) * $throttleMs,
            $elapsedMs,
            'Expected at least (chunk_count - 1) * throttleMs of delay from the inter-chunk throttle.'
        );
    }

    /**
     * Slice 7, T031: the default ($throttleMs = null) path is unaffected —
     * a regression here would silently slow down every existing caller that
     * never asks for throttling. Kept fast and simply asserts no throttling
     * delay was introduced (well under a single $throttleMs-sized sleep).
     */
    public function test_apply_without_a_throttle_has_no_inter_chunk_delay(): void
    {
        $tenant = Tenant::factory()->create();

        for ($n = 1; $n <= 5; $n++) {
            $this->makeTransaction($tenant, ['original_payload' => null]);
        }

        $start = microtime(true);
        $run = $this->runner()->apply(now(), $tenant->id, null, chunkSize: 2);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(5, $run->scanned_count);
        // Comfortably below the throttled test's (chunk_count - 1) * 50ms =
        // 100ms floor, so this fails loudly if any inter-chunk sleep leaks
        // into the default no-throttle path, while leaving headroom for
        // ordinary DB/test-runner overhead.
        $this->assertLessThan(100, $elapsedMs, 'Expected no throttling delay when $throttleMs is not provided.');
    }

    /**
     * Slice 7, T033: a kill-switch sentinel file created mid-run (via a side
     * effect inside the mocked reconstruction step, so this exercises a
     * genuine partial-progress stop rather than a trivially-immediate one)
     * stops the run before its next chunk starts. Chunks fully processed
     * before the stop keep their correct, complete audit records and real
     * transaction_taxes rows; chunks after the stop are never touched at
     * all — not scanned, not recorded.
     */
    public function test_apply_stops_at_the_kill_switch_and_leaves_earlier_chunks_intact(): void
    {
        $tenant = Tenant::factory()->create();

        $killSwitchPath = sys_get_temp_dir().'/tsms-backfill-kill-switch-'.uniqid('', true).'.stop';

        // 5 transactions across 3 chunks of size 2 (2, 2, 1), all otherwise
        // cleanly reconstructable/applicable.
        $transactions = collect();
        for ($n = 1; $n <= 5; $n++) {
            $transactions->push($this->makeTransaction($tenant, [
                'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => $n * 10.00]]]),
                'vat_amount' => $n * 10.00,
            ]));
        }
        $transactions = $transactions->sortBy('id')->values();

        $firstChunkLastTransactionId = $transactions[1]->id;

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $firstChunkLastTransactionId, $killSwitchPath) {
                if ($transaction->id === $firstChunkLastTransactionId) {
                    // Drop the sentinel as a side effect of finishing the
                    // first chunk's last transaction, so the kill-switch
                    // check ahead of the SECOND chunk is what actually
                    // triggers the stop — a genuine mid-run stop, not an
                    // immediate no-op one.
                    touch($killSwitchPath);
                }

                return $real->reconstructTaxRows($transaction);
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        try {
            $run = $this->runner($mock)->apply(now(), $tenant->id, null, chunkSize: 2, killSwitchPath: $killSwitchPath);

            $this->assertSame(TaxBackfillRun::STATUS_STOPPED, $run->status);
            $this->assertNotNull($run->completed_at);

            // First chunk (2 transactions) fully processed and applied.
            $this->assertSame(2, $run->scanned_count);
            $this->assertSame(2, $run->reconstructed_count);

            foreach ($transactions->take(2) as $transaction) {
                $this->assertDatabaseHas('tax_backfill_records', [
                    'run_id' => $run->id,
                    'transaction_pk' => $transaction->id,
                    'outcome' => TaxBackfillOutcome::Applied->value,
                ]);
                $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());
            }

            // Remaining 3 transactions (chunks 2 and 3) were never touched:
            // no audit record and no transaction_taxes row at all.
            foreach ($transactions->slice(2) as $transaction) {
                $this->assertDatabaseMissing('tax_backfill_records', [
                    'run_id' => $run->id,
                    'transaction_pk' => $transaction->id,
                ]);
                $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());
            }
        } finally {
            if (file_exists($killSwitchPath)) {
                unlink($killSwitchPath);
            }
        }
    }

    /**
     * Code review follow-up: proves the `match(true)` precedence in apply()'s
     * final status resolution — STATUS_STOPPED must win even when an
     * EARLIER chunk's transaction genuinely failed (setting
     * $hadUnrecoverableFailure = true) before a LATER chunk trips the
     * kill-switch. None of the other kill-switch tests combine a genuine
     * failure with a kill-switch stop in the same run, so without this test
     * a future reordering of the match arms (checking
     * $hadUnrecoverableFailure before $killSwitchTriggered) would silently
     * report STATUS_FAILED instead and go uncaught.
     *
     * Reuses the double-failure technique from
     * test_a_genuine_db_level_insert_failure_for_one_transaction_does_not_corrupt_the_recording_of_others():
     * the "reconstruction" step hard-deletes the failing transaction's own
     * row as a side effect, so both its transaction_taxes insert and its
     * failed-outcome TaxBackfillRecord write genuinely violate a real FK
     * constraint — this is what makes processTransactionForApply() return
     * false and apply() set $hadUnrecoverableFailure. The same side effect
     * also drops the kill-switch sentinel, so the run's SECOND chunk (never
     * reached) is what actually stops the run — the failure and the stop
     * are genuinely in different chunks.
     */
    public function test_apply_reports_stopped_not_failed_when_an_earlier_chunk_failed_before_the_kill_switch_tripped(): void
    {
        Log::spy();

        $tenant = Tenant::factory()->create();

        $killSwitchPath = sys_get_temp_dir().'/tsms-backfill-kill-switch-'.uniqid('', true).'.stop';

        // Chunk 1 (ids ascending, chunkSize 2): $ok then $failing.
        $ok = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 15.00]]]),
            'vat_amount' => 15.00,
        ]);
        $failing = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 25.00]]]),
            'vat_amount' => 25.00,
        ]);
        // Chunk 2: never processed once the kill-switch trips ahead of it.
        $untouchedC = $this->makeTransaction($tenant, ['original_payload' => null]);
        $untouchedD = $this->makeTransaction($tenant, ['original_payload' => null]);

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $failing, $killSwitchPath) {
                $rows = $real->reconstructTaxRows($transaction);

                if ($transaction->id === $failing->id) {
                    // Simulate the transaction row disappearing mid-scan
                    // (same technique as the Slice 6 double-failure tests):
                    // both the transaction_taxes insert and the
                    // failed-outcome audit write for this transaction
                    // genuinely violate a real FK constraint, so
                    // processTransactionForApply() returns false and
                    // apply() sets $hadUnrecoverableFailure = true.
                    DB::table('transactions')->where('id', $failing->id)->delete();

                    // Drop the kill-switch sentinel as a side effect of
                    // finishing this (first chunk's second) transaction.
                    touch($killSwitchPath);
                }

                return $rows;
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        try {
            $run = $this->runner($mock)->apply(now(), $tenant->id, null, chunkSize: 2, killSwitchPath: $killSwitchPath);

            // The precedence under test: a genuine failure happened, AND the
            // kill-switch tripped — the run must report STOPPED, never
            // FAILED.
            $this->assertSame(TaxBackfillRun::STATUS_STOPPED, $run->status);
            $this->assertNotSame(TaxBackfillRun::STATUS_FAILED, $run->status);
            $this->assertNotNull($run->completed_at);

            // First chunk ($ok + $failing) fully processed.
            $this->assertSame(2, $run->scanned_count);
            $this->assertSame(1, $run->reconstructed_count);
            // failed_count stays 0: the increment lives inside the same
            // now-rolled-back transaction as the failed-outcome audit write,
            // which is exactly what also failed here (Slice 6 code review
            // finding 1's fix) — matching the equivalent double-failure
            // tests above.
            $this->assertSame(0, $run->failed_count);

            $this->assertDatabaseHas('tax_backfill_records', [
                'run_id' => $run->id,
                'transaction_pk' => $ok->id,
                'outcome' => TaxBackfillOutcome::Applied->value,
            ]);
            $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $ok->id)->count());

            // The failing transaction has no audit record and no tax row —
            // both write attempts genuinely failed at the DB level.
            $this->assertDatabaseMissing('tax_backfill_records', [
                'run_id' => $run->id,
                'transaction_pk' => $failing->id,
            ]);
            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $failing->id)->count());

            // Second chunk never touched at all.
            foreach ([$untouchedC, $untouchedD] as $transaction) {
                $this->assertDatabaseMissing('tax_backfill_records', [
                    'run_id' => $run->id,
                    'transaction_pk' => $transaction->id,
                ]);
                $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());
            }

            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function (string $message, array $context) use ($failing) {
                    return str_contains($message, 'could not record a failed-outcome audit row for a transaction during apply')
                        && $context['transaction_id'] === $failing->id;
                });
        } finally {
            if (file_exists($killSwitchPath)) {
                unlink($killSwitchPath);
            }
        }
    }

    /**
     * Slice 7, T033: the default ($killSwitchPath = null) path, and a path
     * that is provided but whose file never exists, both run to completion
     * exactly as before this slice — no behavior change for existing
     * callers/tests.
     */
    public function test_apply_without_a_kill_switch_or_with_a_nonexistent_one_runs_to_completion(): void
    {
        $tenant = Tenant::factory()->create();

        $transactions = collect();
        for ($n = 1; $n <= 4; $n++) {
            $transactions->push($this->makeTransaction($tenant, [
                'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => $n * 10.00]]]),
                'vat_amount' => $n * 10.00,
            ]));
        }

        $nonExistentPath = sys_get_temp_dir().'/tsms-backfill-kill-switch-does-not-exist-'.uniqid('', true).'.stop';
        $this->assertFileDoesNotExist($nonExistentPath);

        $run = $this->runner()->apply(now(), $tenant->id, null, chunkSize: 2, killSwitchPath: $nonExistentPath);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(4, $run->scanned_count);
        $this->assertSame(4, $run->reconstructed_count);

        foreach ($transactions as $transaction) {
            $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());
        }
    }

    /**
     * Slice 7, T032: proves resume holds for the kill-switch-stopped case
     * specifically (T034 already proved the plain "ran to completion twice"
     * case). Stop a run partway via the kill-switch, then re-invoke apply()
     * over the same day with no kill-switch path: it must complete, and
     * every transaction across both runs combined ends up with exactly one
     * applied/audit record — the ones from before the stop converge to
     * skipped_existing in the second run, the ones after are freshly
     * applied.
     */
    public function test_apply_resumes_correctly_after_a_kill_switch_stop(): void
    {
        $tenant = Tenant::factory()->create();

        $killSwitchPath = sys_get_temp_dir().'/tsms-backfill-kill-switch-'.uniqid('', true).'.stop';

        $transactions = collect();
        for ($n = 1; $n <= 4; $n++) {
            $transactions->push($this->makeTransaction($tenant, [
                'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => $n * 10.00]]]),
                'vat_amount' => $n * 10.00,
            ]));
        }
        $transactions = $transactions->sortBy('id')->values();
        $day = $transactions->first()->created_at->copy();

        $firstChunkLastTransactionId = $transactions[1]->id;

        $real = new TaxReconstructionService;
        $mock = \Mockery::mock(TaxReconstructionService::class);
        $mock->shouldReceive('reconstructTaxRows')
            ->andReturnUsing(function (Transaction $transaction) use ($real, $firstChunkLastTransactionId, $killSwitchPath) {
                if ($transaction->id === $firstChunkLastTransactionId) {
                    touch($killSwitchPath);
                }

                return $real->reconstructTaxRows($transaction);
            });
        $mock->shouldReceive('crossCheck')
            ->andReturnUsing(fn (Transaction $transaction, array $rows) => $real->crossCheck($transaction, $rows));

        try {
            $firstRun = $this->runner($mock)->apply($day, $tenant->id, null, chunkSize: 2, killSwitchPath: $killSwitchPath);

            $this->assertSame(TaxBackfillRun::STATUS_STOPPED, $firstRun->status);
            $this->assertSame(2, $firstRun->reconstructed_count);

            $this->assertTrue(file_exists($killSwitchPath));
            unlink($killSwitchPath);

            // Resume: same day, no kill-switch path, real reconstruction
            // service this time.
            $secondRun = $this->runner()->apply($day, $tenant->id);

            $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $secondRun->status);
            $this->assertSame(4, $secondRun->scanned_count);
            // The 2 already-applied transactions converge to skipped_existing...
            $this->assertSame(2, $secondRun->skipped_existing_count);
            // ...and the 2 that were never touched are freshly applied.
            $this->assertSame(2, $secondRun->reconstructed_count);

            // Exactly one real transaction_taxes row per transaction across
            // both runs combined — no duplicates, nothing lost.
            foreach ($transactions as $transaction) {
                $this->assertSame(
                    1,
                    DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count(),
                    "Expected exactly one transaction_taxes row for transaction {$transaction->id}."
                );
            }
        } finally {
            if (file_exists($killSwitchPath)) {
                unlink($killSwitchPath);
            }
        }
    }

    /**
     * Slice 7, T032: resume also holds after a genuine unanticipated escape
     * (STATUS_INTERRUPTED), not just a deliberate kill-switch stop. Reuses
     * Slice 6's "escapes both containment layers" technique
     * (DB::beforeExecuting() forcing a failure on the one always-executes
     * scanned_count increment) to force a run to end `interrupted`, then
     * confirms a fresh apply() invocation over the same day completes
     * correctly afterward.
     */
    public function test_apply_resumes_correctly_after_an_interrupted_run(): void
    {
        $tenant = Tenant::factory()->create();

        $first = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 22.00]]]),
            'vat_amount' => 22.00,
        ]);
        $second = $this->makeTransaction($tenant, [
            'original_payload' => json_encode(['taxes' => [['tax_type' => 'VAT', 'amount' => 33.00]]]),
            'vat_amount' => 33.00,
        ]);

        $day = $first->created_at->copy();

        $scannedCountUpdates = 0;
        DB::beforeExecuting(function (string $query) use (&$scannedCountUpdates) {
            $isScannedCountIncrement = str_starts_with(strtolower(trim($query)), 'update ')
                && str_contains($query, 'tax_backfill_runs')
                && str_contains($query, 'scanned_count');

            if (! $isScannedCountIncrement) {
                return;
            }

            $scannedCountUpdates++;

            if ($scannedCountUpdates === 2) {
                throw new \RuntimeException('Simulated DB failure escaping both containment layers.');
            }
        });

        $runner = $this->runner();

        try {
            $runner->apply($day, $tenant->id, chunkSize: 2);
            $this->fail('Expected apply() to rethrow the exception that escaped both containment layers.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated DB failure escaping both containment layers.', $e->getMessage());
        }

        $firstRun = TaxBackfillRun::where('mode', TaxBackfillRun::MODE_APPLY)
            ->where('window_start', $day->copy()->startOfDay())
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(TaxBackfillRun::STATUS_INTERRUPTED, $firstRun->status);

        // Resume: no forced failures this time, real reconstruction service.
        $secondRun = $this->runner()->apply($day, $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $secondRun->status);
        $this->assertSame(2, $secondRun->scanned_count);
        // Both were already applied (durably committed before the escape,
        // per Slice 6's per-transaction commit-boundary guarantee) -> both
        // converge to skipped_existing on this fresh run.
        $this->assertSame(2, $secondRun->skipped_existing_count);
        $this->assertSame(0, $secondRun->reconstructed_count);

        $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $first->id)->count());
        $this->assertSame(1, DB::table('transaction_taxes')->where('transaction_pk', $second->id)->count());
    }

    /**
     * Slice 9 (T097) + Slice 10 (T018): against today's real schema (both
     * idx_tx_taxes_pk and fk_tx_taxes_pk present, all required columns
     * present), both pre-flight checks pass and apply() proceeds exactly as
     * it did before this slice — with the full observed envelope recorded on
     * the run regardless.
     */
    public function test_apply_records_a_passing_preflight_check_and_proceeds_normally(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 12.00]],
            ]),
            'vat_amount' => 12.00,
        ]);

        $run = $this->runner()->apply($transaction->created_at->copy(), $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->scanned_count);
        $this->assertSame(1, $run->reconstructed_count);

        $this->assertTrue($run->preflight_checks['required_columns']['passed']);
        $this->assertSame([], $run->preflight_checks['required_columns']['missing']);

        $this->assertSame([
            'index_present' => true,
            'fk_present' => true,
            'fk_on_delete_action' => 'cascade',
            'transaction_pk_nullable' => true,
            'passed' => true,
        ], $run->preflight_checks['schema_integrity']);
    }

    /**
     * Slice 10 (T018): dryRun() against today's real schema also runs
     * checkRequiredColumns() and records a passing result — but never
     * touches check() (T097's index/FK/nullability check stays apply()-only),
     * so `schema_integrity` is always null for a dry-run row.
     */
    public function test_dry_run_records_a_passing_required_columns_check_and_proceeds_normally(): void
    {
        $tenant = Tenant::factory()->create();

        $this->makeTransaction($tenant, ['original_payload' => null]);

        $run = $this->runner()->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->scanned_count);

        $this->assertIsArray($run->preflight_checks);
        $this->assertTrue($run->preflight_checks['required_columns']['passed']);
        $this->assertSame([], $run->preflight_checks['required_columns']['missing']);
        $this->assertNull($run->preflight_checks['schema_integrity']);
    }

    /**
     * Captures transactions.vat_amount's exact column definition (type,
     * nullable, default) via Schema::getColumns() so a test that drops it
     * can restore it faithfully afterward — not merely as "a column with
     * this name," per the Slice 10 brief's explicit restoration discipline.
     *
     * @return array{type: string, nullable: bool, default: mixed}
     */
    private function captureVatAmountColumnDefinition(): array
    {
        $column = collect(Schema::getColumns('transactions'))
            ->firstOrFail(fn (array $c) => $c['name'] === 'vat_amount');

        return [
            'type' => $column['type'],
            'nullable' => (bool) $column['nullable'],
            'default' => $column['default'],
        ];
    }

    /**
     * Restores transactions.vat_amount from a definition captured by
     * captureVatAmountColumnDefinition(), positioned back after
     * sc_vat_exempt_sales (its real position in the live schema, confirmed
     * via `SHOW CREATE TABLE transactions`) so the column order matches what
     * it was before the test dropped it.
     *
     * @param  array{type: string, nullable: bool, default: mixed}  $definition
     */
    private function restoreVatAmountColumn(array $definition): void
    {
        $nullClause = $definition['nullable'] ? 'NULL' : 'NOT NULL';
        $defaultClause = $definition['default'] !== null && $definition['default'] !== ''
            ? " DEFAULT '{$definition['default']}'"
            : '';

        DB::statement(
            "ALTER TABLE transactions ADD COLUMN vat_amount {$definition['type']} {$nullClause}{$defaultClause} AFTER sc_vat_exempt_sales"
        );
    }

    /**
     * Slice 10 (T018): a required column missing from `transactions` must
     * fail dryRun() before it scans a single transaction — dryRun() would
     * otherwise fail with a raw, confusing SQL error the first time it tried
     * to read the missing column, rather than a clean pre-flight failure.
     *
     * ALTER TABLE causes an implicit commit in MySQL/InnoDB, flushing this
     * test's ambient RefreshDatabase transaction (the known isolation gap
     * tracked at tests/TestCase.php:38 — Slice 9 already established this
     * exact discipline for its own destructive index/FK tests), so
     * restoration is explicit and verified via a real Schema::hasColumn()
     * assertion inside `finally` itself, never a normal assertion an early
     * failure could skip.
     */
    public function test_dry_run_fails_preflight_and_touches_nothing_when_a_required_transactions_column_is_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 20.00]],
            ]),
            'vat_amount' => 20.00,
        ]);

        $columnDefinition = $this->captureVatAmountColumnDefinition();

        DB::statement('ALTER TABLE transactions DROP COLUMN vat_amount');

        try {
            $run = $this->runner()->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

            $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertSame(0, $run->scanned_count);
            $this->assertSame(0, $run->reconstructed_count);
            $this->assertSame(0, $run->skipped_existing_count);
            $this->assertSame(0, $run->quarantined_count);
            $this->assertSame(0, $run->failed_count);

            $this->assertSame(0, TaxBackfillRecord::where('run_id', $run->id)->count());

            $this->assertIsArray($run->preflight_checks);
            $this->assertFalse($run->preflight_checks['required_columns']['passed']);
            $this->assertContains('transactions.vat_amount', $run->preflight_checks['required_columns']['missing']);
            $this->assertNull($run->preflight_checks['schema_integrity']);
            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());
        } finally {
            $this->restoreVatAmountColumn($columnDefinition);

            $this->assertTrue(
                Schema::hasColumn('transactions', 'vat_amount'),
                'CRITICAL: transactions.vat_amount was not restored after this test — the shared test database schema is now broken for every subsequent test run.'
            );
        }
    }

    /**
     * Slice 10 (T018): the same missing-column scenario proven against
     * apply() — must fail just as cleanly, before scanning or writing
     * anything, and (per the deliberate short-circuit documented on
     * TaxBackfillRunner::apply()) `schema_integrity` stays null because
     * check() (T097's index/FK check) is never even called once
     * checkRequiredColumns() has already failed.
     */
    public function test_apply_fails_preflight_and_touches_nothing_when_a_required_transactions_column_is_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 20.00]],
            ]),
            'vat_amount' => 20.00,
        ]);

        $columnDefinition = $this->captureVatAmountColumnDefinition();

        DB::statement('ALTER TABLE transactions DROP COLUMN vat_amount');

        try {
            $run = $this->runner()->apply($transaction->created_at->copy(), $tenant->id);

            $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertSame(0, $run->scanned_count);

            $this->assertSame(0, TaxBackfillRecord::where('run_id', $run->id)->count());
            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());

            $this->assertIsArray($run->preflight_checks);
            $this->assertFalse($run->preflight_checks['required_columns']['passed']);
            $this->assertContains('transactions.vat_amount', $run->preflight_checks['required_columns']['missing']);
            // The deliberate short-circuit: check() was never called at all,
            // so schema_integrity is null rather than a fabricated pass/fail
            // for a check that never ran.
            $this->assertNull($run->preflight_checks['schema_integrity']);
        } finally {
            $this->restoreVatAmountColumn($columnDefinition);

            $this->assertTrue(
                Schema::hasColumn('transactions', 'vat_amount'),
                'CRITICAL: transactions.vat_amount was not restored after this test — the shared test database schema is now broken for every subsequent test run.'
            );
        }
    }

    /**
     * Slice 9 (T097): idx_tx_taxes_pk missing must fail the run before any
     * transaction is scanned — zero TaxBackfillRecord rows, zero
     * transaction_taxes writes for a transaction that would otherwise have
     * been cleanly reconstructable.
     *
     * MySQL refuses to drop an index that a foreign key still depends on
     * (`Cannot drop index ... needed in a foreign key constraint`), so a
     * throwaway holder index on the same column is added first, purely to
     * satisfy that InnoDB requirement while idx_tx_taxes_pk itself is
     * dropped. It plays no other role in this test and is removed again in
     * the `finally` block below, alongside restoring idx_tx_taxes_pk itself.
     *
     * ALTER TABLE causes an implicit commit in MySQL/InnoDB, which flushes
     * this test's ambient RefreshDatabase transaction — the known isolation
     * gap this repo already tracks (tests/TestCase.php:38, tasks.md
     * Backlog), so automatic per-test schema rollback cannot be relied on
     * here. Restoration is therefore explicit, and verified via a real
     * Schema::hasIndex() assertion inside `finally` itself (not a normal
     * assertion an early return/failure could skip), per the Slice 9
     * brief's test plan.
     */
    public function test_apply_fails_preflight_and_touches_nothing_when_the_index_is_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 20.00]],
            ]),
            'vat_amount' => 20.00,
        ]);

        DB::statement('ALTER TABLE transaction_taxes ADD INDEX idx_tmp_preflight_holder (transaction_pk)');
        DB::statement('ALTER TABLE transaction_taxes DROP INDEX idx_tx_taxes_pk');

        try {
            $run = $this->runner()->apply($transaction->created_at->copy(), $tenant->id);

            $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertSame(0, $run->scanned_count);
            $this->assertSame(0, $run->reconstructed_count);
            $this->assertSame(0, $run->skipped_existing_count);
            $this->assertSame(0, $run->quarantined_count);
            $this->assertSame(0, $run->failed_count);

            $this->assertSame(0, TaxBackfillRecord::where('run_id', $run->id)->count());
            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());

            // Slice 10: required_columns still passes (nothing about this
            // test drops a column) — schema_integrity is the check that
            // fails, now nested under its own envelope key.
            $this->assertIsArray($run->preflight_checks);
            $this->assertTrue($run->preflight_checks['required_columns']['passed']);
            $this->assertIsArray($run->preflight_checks['schema_integrity']);
            $this->assertFalse($run->preflight_checks['schema_integrity']['index_present']);
            $this->assertTrue($run->preflight_checks['schema_integrity']['fk_present']);
            $this->assertFalse($run->preflight_checks['schema_integrity']['passed']);
        } finally {
            DB::statement('ALTER TABLE transaction_taxes ADD INDEX idx_tx_taxes_pk (transaction_pk)');
            DB::statement('ALTER TABLE transaction_taxes DROP INDEX idx_tmp_preflight_holder');

            $this->assertTrue(
                Schema::hasIndex('transaction_taxes', 'idx_tx_taxes_pk'),
                'CRITICAL: idx_tx_taxes_pk was not restored after this test — the shared test database schema is now broken for every subsequent test run.'
            );
        }
    }

    /**
     * Slice 9 (T097): fk_tx_taxes_pk missing must fail the run the same way
     * the missing-index case above does. Same DDL-implicit-commit/manual-
     * restoration discipline: dropping the FK constraint does not require a
     * holder index (unlike the index-drop case, MySQL freely allows removing
     * just the constraint while its backing index stays in place), but
     * restoration is still explicit and verified in `finally`.
     */
    public function test_apply_fails_preflight_and_touches_nothing_when_the_foreign_key_is_missing(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 30.00]],
            ]),
            'vat_amount' => 30.00,
        ]);

        DB::statement('ALTER TABLE transaction_taxes DROP FOREIGN KEY fk_tx_taxes_pk');

        try {
            $run = $this->runner()->apply($transaction->created_at->copy(), $tenant->id);

            $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertSame(0, $run->scanned_count);

            $this->assertSame(0, TaxBackfillRecord::where('run_id', $run->id)->count());
            $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());

            // Slice 10: required_columns still passes — schema_integrity is
            // the check that fails, now nested under its own envelope key.
            $this->assertIsArray($run->preflight_checks);
            $this->assertTrue($run->preflight_checks['required_columns']['passed']);
            $this->assertIsArray($run->preflight_checks['schema_integrity']);
            $this->assertTrue($run->preflight_checks['schema_integrity']['index_present']);
            $this->assertFalse($run->preflight_checks['schema_integrity']['fk_present']);
            $this->assertNull($run->preflight_checks['schema_integrity']['fk_on_delete_action']);
            $this->assertFalse($run->preflight_checks['schema_integrity']['passed']);
        } finally {
            DB::statement('ALTER TABLE transaction_taxes ADD CONSTRAINT fk_tx_taxes_pk FOREIGN KEY (transaction_pk) REFERENCES transactions (id) ON DELETE CASCADE');

            $foreignKeyRestored = collect(Schema::getForeignKeys('transaction_taxes'))
                ->contains(fn (array $fk) => $fk['name'] === 'fk_tx_taxes_pk');

            $this->assertTrue(
                $foreignKeyRestored,
                'CRITICAL: fk_tx_taxes_pk was not restored after this test — the shared test database schema is now broken for every subsequent test run.'
            );
        }
    }

    /**
     * Slice 9 (T097) + Slice 10 (T018): dryRun() must never call check()
     * (T097's index/FK/nullability check stays apply()-only), even though
     * Slice 10 gave dryRun() its first-ever use of
     * TaxBackfillPreflightChecker via checkRequiredColumns(). Proved via a
     * mock with shouldNotReceive('check') rather than merely asserting the
     * outcome, so a future accidental call from inside dryRun() is caught
     * even if it wouldn't otherwise change this run's recorded result.
     */
    public function test_dry_run_never_invokes_check_but_does_invoke_the_required_columns_check(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeTransaction($tenant, ['original_payload' => null]);

        $mockChecker = \Mockery::mock(TaxBackfillPreflightChecker::class);
        $mockChecker->shouldNotReceive('check');
        $mockChecker->shouldReceive('checkRequiredColumns')
            ->once()
            ->andReturn(['missing' => [], 'passed' => true]);

        $run = $this->runner(null, $mockChecker)->dryRun(now()->subDay(), now()->addDay(), $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([
            'required_columns' => ['missing' => [], 'passed' => true],
            'schema_integrity' => null,
        ], $run->preflight_checks);
    }

    /**
     * Slice 17 (T100): the third and final preflight step. When
     * IdleTransactionWatchdog::check() reports a genuinely idle transaction,
     * apply() must refuse before the chunked query is even built — mirroring
     * exactly how T097/T018's own preflight failures short-circuit — and
     * must never call applyLowLockWaitTimeout()/sampleProcesslist() (those
     * are only safe/meaningful once the gate has actually passed).
     */
    public function test_apply_refuses_before_touching_transaction_taxes_when_the_idle_transaction_watchdog_fails(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 20.00]],
            ]),
            'vat_amount' => 20.00,
        ]);

        $mockWatchdog = \Mockery::mock(IdleTransactionWatchdog::class);
        $mockWatchdog->shouldReceive('check')->once()->andReturn([
            'idle_transaction_count' => 1,
            'oldest_age_seconds' => 120,
            'transactions' => [['trx_id' => '42', 'trx_state' => 'RUNNING', 'age_seconds' => 120]],
            'passed' => false,
        ]);
        $mockWatchdog->shouldNotReceive('applyLowLockWaitTimeout');
        $mockWatchdog->shouldNotReceive('sampleProcesslist');

        $run = $this->runner(null, null, $mockWatchdog)->apply($transaction->created_at->copy(), $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_PREFLIGHT_FAILED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(0, $run->scanned_count);
        $this->assertSame(0, $run->reconstructed_count);

        $this->assertSame(0, TaxBackfillRecord::where('run_id', $run->id)->count());
        $this->assertSame(0, DB::table('transaction_taxes')->where('transaction_pk', $transaction->id)->count());

        $this->assertIsArray($run->preflight_checks);
        $this->assertTrue($run->preflight_checks['required_columns']['passed']);
        $this->assertIsArray($run->preflight_checks['schema_integrity']);
        $this->assertTrue($run->preflight_checks['schema_integrity']['passed']);

        $this->assertIsArray($run->preflight_checks['operational_safety']);
        $this->assertFalse($run->preflight_checks['operational_safety']['idle_transaction_gate']['passed']);
        $this->assertSame(1, $run->preflight_checks['operational_safety']['idle_transaction_gate']['idle_transaction_count']);
        $this->assertNull($run->preflight_checks['operational_safety']['processlist_before']);
        $this->assertNull($run->preflight_checks['operational_safety']['processlist_after']);
    }

    /**
     * Slice 17 (T100): once the watchdog gate passes, apply() must call
     * applyLowLockWaitTimeout() exactly once and sampleProcesslist() exactly
     * twice (before the chunk loop and after it completes), and fold both
     * snapshots into `preflight_checks.operational_safety` alongside the
     * gate's own result — a normal, unaffected run otherwise.
     */
    public function test_apply_records_a_passing_operational_safety_gate_and_before_after_processlist_samples(): void
    {
        $tenant = Tenant::factory()->create();

        $transaction = $this->makeTransaction($tenant, [
            'original_payload' => json_encode([
                'taxes' => [['tax_type' => 'VAT', 'amount' => 15.00]],
            ]),
            'vat_amount' => 15.00,
        ]);

        $mockWatchdog = \Mockery::mock(IdleTransactionWatchdog::class);
        $mockWatchdog->shouldReceive('check')->once()->andReturn([
            'idle_transaction_count' => 0,
            'oldest_age_seconds' => null,
            'transactions' => [],
            'passed' => true,
        ]);
        $mockWatchdog->shouldReceive('applyLowLockWaitTimeout')->once();
        $mockWatchdog->shouldReceive('sampleProcesslist')->twice()->andReturn([
            'connection_count' => 3,
            'longest_running_query_age_seconds' => 0,
            'queries_above_threshold_count' => 0,
            'threshold_seconds' => 5,
        ]);

        $run = $this->runner(null, null, $mockWatchdog)->apply($transaction->created_at->copy(), $tenant->id);

        $this->assertSame(TaxBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->reconstructed_count);

        $this->assertTrue($run->preflight_checks['operational_safety']['idle_transaction_gate']['passed']);
        $this->assertIsArray($run->preflight_checks['operational_safety']['processlist_before']);
        $this->assertIsArray($run->preflight_checks['operational_safety']['processlist_after']);
        $this->assertSame(3, $run->preflight_checks['operational_safety']['processlist_before']['connection_count']);
        $this->assertSame(3, $run->preflight_checks['operational_safety']['processlist_after']['connection_count']);
    }

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }
}
