<?php

declare(strict_types=1);

namespace App\Services\Backfill;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Transaction;
use App\Services\DeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
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
    public function __construct(
        protected TaxReconstructionService $reconstructionService,
        protected DeadlockRetryService $retryService
    ) {}

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
     *
     * $tenantId accepts a single tenant id, a list of tenant ids, or null for
     * no tenant restriction (CLI contract's `--tenant` is repeatable — Slice
     * 4). One invocation always produces exactly one TaxBackfillRun row
     * covering every specified tenant, never one run per tenant.
     *
     * @param  int|array<int>|null  $tenantId
     */
    public function dryRun(Carbon $from, Carbon $to, int|array|null $tenantId = null, ?int $limit = null, int $chunkSize = 500): TaxBackfillRun
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
            ->when($tenantId !== null, function ($q) use ($tenantId) {
                is_array($tenantId)
                    ? $q->whereIn('tenant_id', $tenantId)
                    : $q->where('tenant_id', $tenantId);
            });

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
     * Scan transactions created on $day, classify each into exactly one
     * outcome, and — for the clean/reconstructable case only — actually
     * insert the reconstructed rows into `transaction_taxes` (Slice 6,
     * T026-T029). Single-day only: $day resolves to
     * [$day->copy()->startOfDay(), $day->copy()->endOfDay()], so this method
     * has no way to be called across a multi-day window (FR-014a,
     * cli-contract.md's "Day-scoped apply" guarantee). Wiring --apply into
     * the CLI (with --day enforcement at that layer) is a separate follow-up
     * task, not this method's concern.
     *
     * Classification ordering mirrors dryRun()'s processTransaction()
     * exactly: taxes()->exists() first (skipped_existing, no reconstruction
     * attempted at all), then reconstructTaxRows() (quarantined /
     * missing_payload if empty), then crossCheck() (quarantined /
     * cross_check_mismatch if non-empty). Only once all three checks pass
     * does this method insert anything. This ordering is also what makes
     * T034 (idempotency) and T035 (no-overwrite) hold "for free": re-running
     * apply() over the same day converges every previously-applied
     * transaction to skipped_existing on the second pass, and a transaction
     * with a pre-existing linked row is never reconstructed, cross-checked,
     * or touched at all.
     *
     * Transaction-boundary note (differs from dryRun()): dryRun() wraps each
     * *chunk's* audit writes in one DB::transaction() because nothing in it
     * ever writes a real tax row — losing an audit chunk to a rollback is
     * acceptable. Here, a chunk's already-successfully-inserted real
     * `transaction_taxes` rows must never be rolled back because a
     * *different* transaction in the same chunk hit a DB error, so each
     * transaction's insert + its TaxBackfillRecord write are wrapped in one
     * short DB::transaction() *per transaction processed* (via
     * DeadlockRetryService::withDeadlockRetry(), this repo's established
     * convention — see TransactionIngestService::ingest()), not per chunk.
     *
     * Failure containment mirrors dryRun()'s two-layer design, adapted for
     * the per-transaction boundary: processTransactionForApply() catches
     * anything from classification/insert and records a `failed` outcome in
     * its own short transaction; if even that recording throws (a genuine
     * DB error), it's logged and reported via the return value so this
     * method marks the run `failed` rather than `completed`. This method
     * itself catches anything that escapes that containment entirely and
     * marks the run `interrupted` before re-throwing, so a run is never left
     * sitting at `status = 'running'` indefinitely.
     *
     * Slice 7 additions (T031/T033), both chunk-granularity, never
     * per-transaction — a 500-per-chunk default makes per-transaction
     * throttling/checking far too fine-grained and would defeat the point of
     * chunking for throughput:
     *  - $throttleMs (T031): when non-null, sleep that many milliseconds
     *    after each chunk's transactions have all been processed. Default
     *    `null` preserves today's behavior (no delay) for every existing
     *    caller/test.
     *  - $killSwitchPath (T033): when non-null, checked for existence
     *    *before* a new chunk's transactions are processed (the chunk has
     *    already been fetched from the DB by chunkById() by that point, but
     *    none of its rows are touched). If the sentinel file exists, the run
     *    stops there — the chunk about to start is never processed, nor is
     *    any chunk after it — and ends `STATUS_STOPPED`, never
     *    `failed`/`interrupted`/`completed`: this is a deliberate operator
     *    stop, not an error. Resume is not new production logic here — it
     *    falls out of the same `taxes()->exists()` idempotency check that
     *    already makes re-running `apply()` over the same day converge
     *    (T034); re-invoking `apply()` after a kill-switch stop (or any
     *    other interruption) simply picks up where the audit trail left off.
     *
     * @param  int|array<int>|null  $tenantId
     */
    public function apply(
        Carbon $day,
        int|array|null $tenantId = null,
        ?int $limit = null,
        int $chunkSize = 500,
        ?int $throttleMs = null,
        ?string $killSwitchPath = null
    ): TaxBackfillRun {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $run = TaxBackfillRun::create([
            'window_start' => $from,
            'window_end' => $to,
            'mode' => TaxBackfillRun::MODE_APPLY,
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
            ->when($tenantId !== null, function ($q) use ($tenantId) {
                is_array($tenantId)
                    ? $q->whereIn('tenant_id', $tenantId)
                    : $q->where('tenant_id', $tenantId);
            });

        $processed = 0;
        $reachedLimit = false;
        $hadUnrecoverableFailure = false;
        $killSwitchTriggered = false;

        try {
            $query->chunkById($chunkSize, function ($transactions) use ($run, $limit, $throttleMs, $killSwitchPath, &$processed, &$reachedLimit, &$hadUnrecoverableFailure, &$killSwitchTriggered) {
                // T033: checked before this chunk's transactions are
                // touched. chunkById() has already fetched $transactions
                // from the DB by this point, but none of its rows are
                // processed/recorded if the sentinel file is present —
                // scanned_count and every other counter are left exactly as
                // they were after the previous chunk.
                if ($killSwitchPath !== null && file_exists($killSwitchPath)) {
                    $killSwitchTriggered = true;

                    // Returning false stops chunkById from fetching further chunks.
                    return false;
                }

                foreach ($transactions as $transaction) {
                    if ($limit !== null && $processed >= $limit) {
                        $reachedLimit = true;
                        break;
                    }

                    if (! $this->processTransactionForApply($transaction, $run)) {
                        $hadUnrecoverableFailure = true;
                    }

                    $processed++;
                }

                // T031: chunk-granularity throttle only — sleeps once after
                // this whole chunk's transactions have been processed, never
                // per-transaction.
                if ($throttleMs !== null) {
                    usleep($throttleMs * 1000);
                }

                // Returning false stops chunkById from fetching further chunks.
                return ! $reachedLimit;
            });
        } catch (Throwable $e) {
            Log::error('TaxBackfillRunner: apply run interrupted by an unexpected error', [
                'run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);

            // Discard any stale in-memory counter left by whatever
            // just-rolled-back statement caused this escape (see the
            // matching comment in processTransactionForApply()'s outer
            // catch) before this update() call — otherwise Eloquent's dirty
            // tracking would resurrect that never-actually-committed value
            // into the DB alongside the status/completed_at change below.
            $run->refresh();

            $run->update([
                'status' => TaxBackfillRun::STATUS_INTERRUPTED,
                'completed_at' => now(),
            ]);

            throw $e;
        }

        // T033: a deliberate kill-switch stop takes precedence over
        // $hadUnrecoverableFailure — it is not an error condition, so it
        // must never be reported as `failed` even if an earlier chunk (fully
        // processed before the stop) happened to contain a genuinely
        // unrecordable transaction.
        $status = match (true) {
            $killSwitchTriggered => TaxBackfillRun::STATUS_STOPPED,
            $hadUnrecoverableFailure => TaxBackfillRun::STATUS_FAILED,
            default => TaxBackfillRun::STATUS_COMPLETED,
        };

        $run->update([
            'status' => $status,
            'completed_at' => now(),
        ]);

        return $run;
    }

    /**
     * Classify and (for the clean case) apply a single transaction, in its
     * own short DeadlockRetryService/DB::transaction() boundary — never a
     * chunk-wide one, per apply()'s docblock. Returns false only when even
     * recording the `failed` outcome itself throws (a genuine DB error, not
     * merely a reconstruction/insert failure) — mirrors
     * processTransaction()'s return-value contract in dryRun().
     *
     * Code-review fix: every `$run->increment(...)` call for the outcome
     * counters lives *inside* the same DeadlockRetryService/DB::transaction()
     * closure as the TaxBackfillRecord write (and the tax-row insert, for
     * `Applied`) it corresponds to — never after that closure returns. An
     * increment failing after the closure already committed used to leave a
     * committed `applied`/`quarantined`/`skipped_existing` TaxBackfillRecord
     * in place while control fell through to the outer catch, which then
     * wrote a *second*, contradictory `failed` TaxBackfillRecord for the
     * same transaction and double-counted the run totals — breaking the
     * one-transaction-one-outcome invariant. With the increment inside the
     * transaction, a failure there rolls back the whole unit (no tax row, no
     * audit record, no partial counter), and the outer catch's
     * failure-recording path runs against a transaction with zero prior
     * state, exactly as the two-layer containment design already intends
     * for every other kind of mid-transaction failure. `scanned_count` is
     * the one exception: it is incremented once per transaction regardless
     * of outcome via `finally`, deliberately outside any of the per-outcome
     * transactions, since it must still increase even when scanning
     * genuinely fails.
     */
    protected function processTransactionForApply(Transaction $transaction, TaxBackfillRun $run): bool
    {
        $reconstructedRows = [];

        try {
            if ($transaction->taxes()->exists()) {
                $this->retryService->withDeadlockRetry(function () use ($run, $transaction) {
                    $this->recordOutcome($run, $transaction, TaxBackfillOutcome::SkippedExisting, null, [], true);
                    $run->increment('skipped_existing_count');
                });

                return true;
            }

            $reconstructedRows = $this->reconstructionService->reconstructTaxRows($transaction);

            if ($reconstructedRows === []) {
                $this->retryService->withDeadlockRetry(function () use ($run, $transaction) {
                    $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Quarantined, TaxBackfillReasonCode::MissingPayload->value, [], false);
                    $run->increment('quarantined_count');
                });

                return true;
            }

            $mismatches = $this->reconstructionService->crossCheck($transaction, $reconstructedRows);

            if ($mismatches !== []) {
                $this->retryService->withDeadlockRetry(function () use ($run, $transaction, $reconstructedRows) {
                    $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Quarantined, TaxBackfillReasonCode::CrossCheckMismatch->value, $reconstructedRows, false);
                    $run->increment('quarantined_count');
                });

                return true;
            }

            // Clean case: reconstruction is trustworthy and has zero
            // existing linked rows. Insert the real tax rows, the `applied`
            // audit record, and the counter increment atomically, in one
            // short transaction (deadlock-retried) scoped to this
            // transaction alone. The transaction boundary comes solely from
            // DeadlockRetryService::withDeadlockRetry() itself (it already
            // wraps its callback in DB::transaction($callback, 1) — see
            // that class) — this closure must NOT open its own nested
            // DB::transaction(), or a genuine deadlock on a statement in
            // here surfaces as Laravel's nested-transaction DeadlockException
            // (ManagesTransactions::handleTransactionException(), thrown
            // when $this->transactions > 1), which is not a QueryException
            // and so is invisible to withDeadlockRetry()'s `catch
            // (QueryException $e)` — defeating retry entirely.
            $this->retryService->withDeadlockRetry(function () use ($run, $transaction, $reconstructedRows) {
                $this->insertTaxRows($transaction, $reconstructedRows);
                $this->recordOutcome($run, $transaction, TaxBackfillOutcome::Applied, null, $reconstructedRows, false);
                $run->increment('reconstructed_count');
            });

            return true;
        } catch (Throwable $e) {
            // Discard any stale in-memory counter mutation left by the
            // just-rolled-back attempt above. Eloquent's increment() sets
            // the attribute in memory *before* issuing its UPDATE
            // (Model::incrementOrDecrement()); if that specific UPDATE is
            // what failed, the in-memory value never gets resynced to the
            // (correctly rolled-back) DB value via syncOriginalAttribute().
            // Left uncorrected, that stale value would look "dirty" to
            // Eloquent and get resurrected into the DB the next time
            // anything calls $run->update(...) or $run->save() — including
            // apply()'s own final status-setting update. refresh() re-reads
            // the actually-persisted row so every counter this method (and
            // apply() afterward) reasons about is truthful.
            $run->refresh();

            try {
                $this->retryService->withDeadlockRetry(function () use ($run, $transaction, $reconstructedRows, $e) {
                    $this->recordOutcome(
                        $run,
                        $transaction,
                        TaxBackfillOutcome::Failed,
                        $this->describeException($e),
                        $reconstructedRows,
                        false
                    );
                    $run->increment('failed_count');
                });

                return true;
            } catch (Throwable $recordingException) {
                // Recording the failure itself failed (e.g. a genuine DB
                // error, not just a reconstruction/insert failure) — don't
                // let this vanish silently: log both exceptions and surface
                // it via the return value so apply() marks the run `failed`
                // instead of `completed`. No TaxBackfillRecord exists for
                // this transaction; it genuinely could not be written, and —
                // because the insert attempt (if one happened) was wrapped
                // in the same now-rolled-back transaction as this failed
                // recording attempt — no orphan `transaction_taxes` row was
                // left behind either. failed_count is intentionally NOT
                // incremented here: the transaction that would have carried
                // it never committed, so bumping the in-memory counter here
                // would itself double-count against a future successful
                // retry/rerun's accounting. The gap is visible instead via
                // the logged error below and the run ending in `failed`
                // status rather than `completed`. Also discard any stale
                // in-memory counter this now-rolled-back attempt may have
                // left behind (same reasoning as the refresh() above), so
                // apply()'s own final $run->update() can't resurrect it.
                $run->refresh();

                Log::error('TaxBackfillRunner: could not record a failed-outcome audit row for a transaction during apply', [
                    'run_id' => $run->id,
                    'transaction_id' => $transaction->id,
                    'original_exception' => $e->getMessage(),
                    'recording_exception' => $recordingException->getMessage(),
                ]);

                return false;
            }
        } finally {
            $run->increment('scanned_count');
        }
    }

    /**
     * Build and insert one multi-row `insert()` statement for every
     * reconstructed row belonging to $transaction (T029). Cross-transaction
     * batching (multiple transactions' rows in one statement) is explicitly
     * deferred to T078 — out of scope here.
     *
     * `created_at` is always the *parent transaction's own* `created_at` —
     * never `now()` — per FR-014/data-model.md/T068a: using insertion time
     * would fail day-level reconciliation by construction. `updated_at`, by
     * contrast, genuinely is being written now (this row did not exist a
     * moment ago), so it uses `now()` — only when Schema::hasColumn()
     * confirms the column exists, mirroring
     * TransactionIngestService::insertTaxes()'s own guard
     * (TransactionIngestService.php:450-455) both in its presence-check and
     * its `now()` value. This method does not reuse or extract from that
     * method (R5/Q8/N10: this class's insert semantics diverge from the live
     * ingestion path), it only mirrors the same defensive Schema guard.
     *
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $reconstructedRows
     */
    protected function insertTaxRows(Transaction $transaction, array $reconstructedRows): void
    {
        if ($transaction->id === null) {
            // Defensive only — taxes()->exists() and everything upstream
            // already require a persisted transaction with a non-null id.
            // This should be unreachable, but per data-model.md's own
            // warning that transaction_pk is nullable at the DB level (the
            // exact nullability that caused the original 3.24M-row defect),
            // refuse to insert rather than let a null slip through.
            throw new RuntimeException('TaxBackfillRunner: refusing to insert transaction_taxes rows for a transaction with a null id.');
        }

        $hasUpdatedAt = Schema::hasColumn('transaction_taxes', 'updated_at');

        $rows = array_map(function (array $row) use ($transaction, $hasUpdatedAt) {
            $insertRow = [
                'transaction_pk' => $transaction->id,
                'tax_type' => $row['tax_type'],
                'amount' => $row['amount'],
                'created_at' => $transaction->created_at,
            ];

            if ($hasUpdatedAt) {
                $insertRow['updated_at'] = now();
            }

            return $insertRow;
        }, $reconstructedRows);

        DB::table('transaction_taxes')->insert($rows);
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
