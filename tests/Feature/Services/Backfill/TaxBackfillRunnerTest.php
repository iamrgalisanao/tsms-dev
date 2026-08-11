<?php

namespace Tests\Feature\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Services\Backfill\TaxBackfillOutcome;
use App\Services\Backfill\TaxBackfillReasonCode;
use App\Services\Backfill\TaxBackfillRunner;
use App\Services\Backfill\TaxReconstructionService;
use App\Services\DeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 3 (T016).
 *
 * Covers TaxBackfillRunner::dryRun() — wiring Slice 1's TaxReconstructionService
 * and Slice 2's audit persistence layer together with correct chunking and
 * classification. Dry-run only: no --apply path, no CLI, exists here yet.
 */
class TaxBackfillRunnerTest extends TestCase
{
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

    private function runner(?TaxReconstructionService $service = null): TaxBackfillRunner
    {
        return new TaxBackfillRunner($service ?? new TaxReconstructionService, new DeadlockRetryService);
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

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }
}
