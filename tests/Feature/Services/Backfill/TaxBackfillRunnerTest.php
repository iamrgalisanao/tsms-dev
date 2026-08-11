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
        return new TaxBackfillRunner($service ?? new TaxReconstructionService);
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

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }
}
