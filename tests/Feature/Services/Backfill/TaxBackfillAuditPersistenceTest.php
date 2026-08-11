<?php

namespace Tests\Feature\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Backfill\TaxBackfillOutcome;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 2 (T015).
 *
 * Covers the audit/quarantine persistence layer built in this slice —
 * TaxBackfillRun + TaxBackfillRecord — in isolation. There is no runner or
 * CLI command yet (that's T016+, a later slice); these tests only exercise
 * the models/migrations directly, the way a future runner will use them.
 */
class TaxBackfillAuditPersistenceTest extends TestCase
{
    private function makeTransaction(Tenant $tenant): Transaction
    {
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        return Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ]);
    }

    public function test_run_and_records_can_be_created_with_a_mix_of_outcomes(): void
    {
        $tenant = Tenant::factory()->create();

        $run = TaxBackfillRun::factory()->create([
            'mode' => TaxBackfillRun::MODE_DRY_RUN,
        ]);

        $this->assertSame(TaxBackfillRun::MODE_DRY_RUN, $run->fresh()->mode);

        $applied = TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $skippedExisting = TaxBackfillRecord::factory()->skippedExisting()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $quarantinedMissingPayload = TaxBackfillRecord::factory()->quarantined('missing_payload')->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $quarantinedCrossCheckMismatch = TaxBackfillRecord::factory()->quarantined('cross_check_mismatch')->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $failed = TaxBackfillRecord::factory()->failed('db_error')->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertCount(5, $run->fresh()->records);

        $this->assertSame(TaxBackfillOutcome::Applied->value, $applied->outcome);
        $this->assertSame(TaxBackfillOutcome::SkippedExisting->value, $skippedExisting->outcome);
        $this->assertSame('missing_payload', $quarantinedMissingPayload->reason_code);
        $this->assertSame('cross_check_mismatch', $quarantinedCrossCheckMismatch->reason_code);
        $this->assertSame(TaxBackfillOutcome::Failed->value, $failed->outcome);
        $this->assertSame('db_error', $failed->reason_code);

        // Relationships resolve both directions.
        $this->assertTrue($applied->run->is($run));
        $this->assertTrue($applied->transaction->is(Transaction::find($applied->transaction_pk)));
    }

    /**
     * NOTE: this slice has no runner yet, so nothing computes `tax_backfill_runs`
     * counters from `tax_backfill_records` rows — that's future work for the
     * runner slice (T016+). This test only proves the two are *representable*
     * without conflict: a run's manually-set counters and a matching set of
     * manually-created records can coexist and be queried consistently. It is
     * not exercising any real counter-computation mechanism.
     */
    public function test_manually_set_run_counters_and_manually_created_records_are_mutually_consistent(): void
    {
        $tenant = Tenant::factory()->create();

        $outcomes = [
            'applied' => 2,
            'skipped_existing' => 1,
            'quarantined' => 2,
            'failed' => 1,
        ];

        $run = TaxBackfillRun::factory()->create([
            'scanned_count' => array_sum($outcomes),
            'reconstructed_count' => $outcomes['applied'],
            'skipped_existing_count' => $outcomes['skipped_existing'],
            'quarantined_count' => $outcomes['quarantined'],
            'failed_count' => $outcomes['failed'],
        ]);

        for ($i = 0; $i < $outcomes['applied']; $i++) {
            TaxBackfillRecord::factory()->applied()->create([
                'run_id' => $run->id,
                'transaction_pk' => $this->makeTransaction($tenant)->id,
                'tenant_id' => $tenant->id,
            ]);
        }

        TaxBackfillRecord::factory()->skippedExisting()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        TaxBackfillRecord::factory()->quarantined('missing_payload')->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);
        TaxBackfillRecord::factory()->quarantined('cross_check_mismatch')->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        TaxBackfillRecord::factory()->failed()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        // The run's own counters...
        $run->refresh();
        $this->assertSame($outcomes['applied'], $run->reconstructed_count);
        $this->assertSame($outcomes['skipped_existing'], $run->skipped_existing_count);
        $this->assertSame($outcomes['quarantined'], $run->quarantined_count);
        $this->assertSame($outcomes['failed'], $run->failed_count);

        // ...are derivable from (and consistent with) the row-level records.
        $this->assertSame(
            $run->reconstructed_count,
            $run->records()->where('outcome', TaxBackfillOutcome::Applied->value)->count()
        );
        $this->assertSame(
            $run->skipped_existing_count,
            $run->records()->where('outcome', TaxBackfillOutcome::SkippedExisting->value)->count()
        );
        $this->assertSame(
            $run->quarantined_count,
            $run->records()->where('outcome', TaxBackfillOutcome::Quarantined->value)->count()
        );
        $this->assertSame(
            $run->failed_count,
            $run->records()->where('outcome', TaxBackfillOutcome::Failed->value)->count()
        );
        $this->assertSame($run->scanned_count, $run->records()->count());
    }

    public function test_dry_run_audit_records_write_nothing_to_transaction_taxes(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create(['mode' => TaxBackfillRun::MODE_DRY_RUN]);

        $transactionIds = [];

        foreach (['applied', 'skippedExisting', 'quarantined', 'failed'] as $state) {
            $transaction = $this->makeTransaction($tenant);
            $transactionIds[] = $transaction->id;

            $factory = TaxBackfillRecord::factory();
            $factory = match ($state) {
                'applied' => $factory->applied(),
                'skippedExisting' => $factory->skippedExisting(),
                'quarantined' => $factory->quarantined('missing_payload'),
                'failed' => $factory->failed(),
            };

            $factory->create([
                'run_id' => $run->id,
                'transaction_pk' => $transaction->id,
                'tenant_id' => $tenant->id,
                'reconstructed_tax_rows' => [
                    ['tax_type' => 'VAT', 'amount' => 12.34],
                ],
            ]);
        }

        // Even the 'applied' audit row — which records what *would* be
        // written — must not itself have written anything to the real
        // transaction_taxes table. This persistence layer is inert with
        // respect to the actual tax table; only a future runner writes
        // there, and only in `apply` mode.
        $this->assertSame(0, DB::table('transaction_taxes')
            ->whereIn('transaction_pk', $transactionIds)
            ->count());

        $this->assertSame(4, $run->fresh()->records()->count());
    }

    public function test_quarantined_outcome_without_reason_code_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();
        $transaction = $this->makeTransaction($tenant);

        $this->expectException(\InvalidArgumentException::class);

        TaxBackfillRecord::factory()->create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
            'outcome' => TaxBackfillOutcome::Quarantined->value,
            'reason_code' => null,
        ]);
    }

    public function test_failed_outcome_without_reason_code_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();
        $transaction = $this->makeTransaction($tenant);

        $this->expectException(\InvalidArgumentException::class);

        TaxBackfillRecord::factory()->create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
            'outcome' => TaxBackfillOutcome::Failed->value,
            'reason_code' => null,
        ]);
    }

    public function test_updating_a_record_to_quarantined_without_reason_code_is_also_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();

        $record = TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $record->update([
            'outcome' => TaxBackfillOutcome::Quarantined->value,
            'reason_code' => null,
        ]);
    }

    public function test_quarantined_outcome_with_an_invalid_reason_code_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();
        $transaction = $this->makeTransaction($tenant);

        $this->expectException(\InvalidArgumentException::class);

        // A typo of 'missing_payload' — present, but not one of
        // TaxBackfillReasonCode's two defined cases. Presence alone must
        // not be enough for `quarantined`.
        TaxBackfillRecord::factory()->create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
            'outcome' => TaxBackfillOutcome::Quarantined->value,
            'reason_code' => 'mssing_paylod_TYPO',
        ]);
    }

    public function test_failed_outcome_accepts_a_reason_code_outside_the_quarantine_enum(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();
        $transaction = $this->makeTransaction($tenant);

        // Deliberately not one of TaxBackfillReasonCode's cases: `failed` is
        // presence-only (not restricted to that closed set), since
        // failure-specific codes aren't enumerated yet (T013 only defines
        // the two quarantine reasons).
        $record = TaxBackfillRecord::factory()->create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
            'outcome' => TaxBackfillOutcome::Failed->value,
            'reason_code' => 'some_future_failure_specific_code',
        ]);

        $this->assertSame('some_future_failure_specific_code', $record->reason_code);
    }

    public function test_a_run_cannot_be_deleted_while_it_still_has_records(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();

        TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $run->id,
            'transaction_pk' => $this->makeTransaction($tenant)->id,
            'tenant_id' => $tenant->id,
        ]);

        // run_id's FK is deliberately RESTRICT (no cascade) — deleting a run
        // must not silently wipe its audit records, mirroring transaction_pk's
        // own no-cascade reasoning on the other side of this table.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $run->delete();
    }
}
