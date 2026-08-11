<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates a dry-run pass of the transaction_taxes backfill (T016,
 * Slice 3): chunked scan over `transactions`, classifying each one via
 * App\Services\Backfill\TaxReconstructionService and recording the result
 * through App\Models\TaxBackfillRecord/TaxBackfillRun.
 *
 * Deliberately dry-run only for this slice: nothing here ever writes to
 * `transaction_taxes`, under any classification, including `applied`. A
 * `applied` outcome recorded here means "reconstruction is clean and would
 * be applied by a real --apply run" — the run's own `mode` column (always
 * `dry-run` for this class) is what disambiguates projected-vs-real. A
 * later slice adds the real `--apply` write path (with DeadlockRetryService
 * and the CLI command) alongside this class, not inside it.
 */
class TaxBackfillRunner
{
    public function __construct(protected TaxReconstructionService $reconstructionService) {}

    /**
     * Scan transactions created between $from and $to (inclusive), classify
     * each into exactly one outcome, and persist a TaxBackfillRecord per
     * transaction plus rolled-up counters on the returned TaxBackfillRun.
     *
     * Never writes to `transaction_taxes`. Chunked via chunkById() ordered by
     * `id` (research.md R9) so the window is never loaded into memory at
     * once; each chunk's TaxBackfillRecord writes are wrapped in one short
     * DB transaction (never a window-wide one). A single transaction's
     * processing failure is caught per-transaction and recorded as `failed`
     * — it never aborts the surrounding chunk transaction or the run.
     *
     * Two layers of failure containment:
     *  - Per-transaction (processTransaction()): if even recording the
     *    `failed` outcome itself throws (e.g. a genuine DB error on the
     *    audit write), that is caught, logged, and the scan continues — the
     *    rest of the chunk is never rolled back for one bad row. The run
     *    still finishes its scan but ends in `failed` status rather than
     *    `completed`, so the gap is visible rather than silently swallowed.
     *  - Run-level (this method): if something entirely unanticipated
     *    escapes both of the above (e.g. a failure outside per-transaction
     *    handling altogether), the run is marked `interrupted` with a
     *    `completed_at` timestamp before the exception is re-thrown — a run
     *    must never be left sitting at `status = 'running'` indefinitely.
     */
    public function dryRun(Carbon $from, Carbon $to, ?int $tenantId = null, ?int $limit = null, int $chunkSize = 500): TaxBackfillRun
    {
        $run = TaxBackfillRun::create([
            'window_start' => $from,
            'window_end' => $to,
            'mode' => TaxBackfillRun::MODE_DRY_RUN,
            'scanned_count' => 0,
            'reconstructed_count' => 0,
            'skipped_existing_count' => 0,
            'quarantined_count' => 0,
            'failed_count' => 0,
            'status' => TaxBackfillRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $query = Transaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId));

        $processed = 0;
        $reachedLimit = false;
        $hadUnrecoverableFailure = false;

        try {
            $query->chunkById($chunkSize, function ($transactions) use ($run, $limit, &$processed, &$reachedLimit, &$hadUnrecoverableFailure) {
                DB::transaction(function () use ($transactions, $run, $limit, &$processed, &$reachedLimit, &$hadUnrecoverableFailure) {
                    foreach ($transactions as $transaction) {
                        if ($limit !== null && $processed >= $limit) {
                            $reachedLimit = true;
                            break;
                        }

                        if (! $this->processTransaction($transaction, $run)) {
                            $hadUnrecoverableFailure = true;
                        }

                        $processed++;
                    }
                });

                // Returning false stops chunkById from fetching further chunks.
                return ! $reachedLimit;
            });
        } catch (Throwable $e) {
            Log::error('TaxBackfillRunner: dry run interrupted by an unexpected error', [
                'run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);

            $run->update([
                'status' => TaxBackfillRun::STATUS_INTERRUPTED,
                'completed_at' => now(),
            ]);

            throw $e;
        }

        $run->update([
            'status' => $hadUnrecoverableFailure ? TaxBackfillRun::STATUS_FAILED : TaxBackfillRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        return $run;
    }

    /**
     * Classify a single transaction and persist exactly one TaxBackfillRecord
     * for it, incrementing the matching counter on $run. Any unexpected
     * exception during classification is caught here and recorded as a
     * `failed` outcome instead of propagating — a single bad transaction must
     * never abort the rest of the chunk/run.
     *
     * Returns false only in the (expected to be rare) case where recording
     * the `failed` outcome itself also throws — e.g. a genuine DB error on
     * the audit write, not merely a reconstruction failure. That case is
     * logged rather than raised, so the caller can keep scanning the rest of
     * the chunk instead of losing every already-recorded row in it.
     */
    protected function processTransaction(Transaction $transaction, TaxBackfillRun $run): bool
    {
        $reconstructedRows = [];

        try {
            if ($transaction->taxes()->exists()) {
                $this->recordOutcome($run, $transaction, TaxBackfillOutcome::SkippedExisting, null, [], true);
                $run->increment('skipped_existing_count');

                return true;
            }

            $reconstructedRows = $this->reconstructionService->reconstructTaxRows($transaction);

            if ($reconstructedRows === []) {
                $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Quarantined, TaxBackfillReasonCode::MissingPayload->value, [], false);
                $run->increment('quarantined_count');

                return true;
            }

            $mismatches = $this->reconstructionService->crossCheck($transaction, $reconstructedRows);

            if ($mismatches !== []) {
                $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Quarantined, TaxBackfillReasonCode::CrossCheckMismatch->value, $reconstructedRows, false);
                $run->increment('quarantined_count');

                return true;
            }

            // Dry-run "applied": reconstruction is clean and would be
            // applied by a real --apply run. No write to transaction_taxes
            // happens here or anywhere else in this class.
            $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Applied, null, $reconstructedRows, false);
            $run->increment('reconstructed_count');

            return true;
        } catch (Throwable $e) {
            try {
                $this->recordOutcome(
                    $run,
                    $transaction,
                    TaxBackfillOutcome::Failed,
                    $this->describeException($e),
                    $reconstructedRows,
                    false
                );
                $run->increment('failed_count');

                return true;
            } catch (Throwable $recordingException) {
                // Recording the failure itself failed (e.g. a genuine DB
                // error, not just a reconstruction failure) — don't let this
                // vanish silently: log both exceptions and surface it via
                // the return value so dryRun() marks the run `failed`
                // instead of `completed`. No TaxBackfillRecord exists for
                // this transaction; it genuinely could not be written.
                Log::error('TaxBackfillRunner: could not record a failed-outcome audit row for a transaction', [
                    'run_id' => $run->id,
                    'transaction_id' => $transaction->id,
                    'original_exception' => $e->getMessage(),
                    'recording_exception' => $recordingException->getMessage(),
                ]);

                $run->increment('failed_count');

                return false;
            }
        } finally {
            $run->increment('scanned_count');
        }
    }

    /**
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $reconstructedRows
     */
    protected function recordOutcome(
        TaxBackfillRun $run,
        Transaction $transaction,
        TaxBackfillOutcome $outcome,
        ?string $reasonCode,
        array $reconstructedRows,
        bool $hadLinkedRowsBefore
    ): void {
        TaxBackfillRecord::create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $transaction->tenant_id,
            'reconstructed_tax_rows' => $reconstructedRows,
            'had_linked_rows_before' => $hadLinkedRowsBefore,
            'outcome' => $outcome->value,
            'reason_code' => $reasonCode,
        ]);
    }

    /**
     * Short class name + message, truncated to a sane length for the
     * `reason_code` column (a plain nullable string, T013's docblock).
     */
    protected function describeException(Throwable $e): string
    {
        $description = class_basename($e).': '.$e->getMessage();

        return Str::limit($description, 250, '');
    }
}
