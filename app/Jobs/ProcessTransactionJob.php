<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\TransactionValidationService;
use App\Support\Metrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Exception;

class ProcessTransactionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Primary key ID of the transaction (we re-query for freshest state & lean payload).
     */
    protected int $transactionId;

    /** Max processing attempts (framework also governs retries). */
    public $tries = 3;
    
    /** Job timeout in seconds */
    public $timeout = 120;
    
    /** Backoff delay in seconds between retries */
    public $backoff = 10;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
        // Default to critical processing queue only if no queue was preset by the dispatcher (e.g., sharded queues)
        if (empty($this->queue)) {
            $this->onQueue('transaction-processing');
        }
    }

    /**
     * Ensure only one job per transaction ID is on the queue at a time.
     */
    public function uniqueId(): string
    {
        return 'txn:' . (string) $this->transactionId;
    }

    /**
     * Handles the processing of a transaction, including validation and job tracking.
     *
     * This method performs the following steps:
     * 1. Logs the start of transaction processing with relevant details.
     * 2. Refreshes the transaction model to ensure up-to-date data.
     * 3. Creates a TransactionJob record to track the processing attempt.
     * 4. Creates a TransactionValidation record to track the validation status.
     * 5. Runs transaction validation using the provided TransactionValidationService.
     * 6. If validation fails:
     *    - Updates the validation and job records with error status and details.
     *    - Throws an exception with the validation error messages.
     * 7. If validation passes:
     *    - Updates the validation and job records with completed status.
     * 8. Handles any thrown exceptions by invoking the error handler and rethrowing.
     *
     * @param TransactionValidationService $validationService The service used to validate the transaction.
     * @throws Exception If transaction validation fails.
     * @throws \Throwable For any other errors during processing.
     */
    public function handle(TransactionValidationService $validationService)
    {
            Log::debug('ProcessTransactionJob started', [
                'transaction_pk' => $this->transactionId,
                'attempt' => $this->attempts()
            ]);

        $started = microtime(true);
        try {
            $transaction = Transaction::with(['adjustments', 'taxes'])->find($this->transactionId);
            if (!$transaction) {
                    Log::debug('Early exit: transaction not found', [
                        'transaction_pk' => $this->transactionId,
                        'attempt' => $this->attempts()
                    ]);
                Log::error('Transaction not found for processing', [
                    'transaction_pk' => $this->transactionId
                ]);
                return;
            }

            Log::debug('Starting transaction processing', [
                'transaction_pk' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
                'attempt' => $this->attempts(),
                'validation_status' => $transaction->validation_status,
                'job_status' => $transaction->job_status
            ]);

            // Short‑circuit if already processed / terminal (ADR-004 D1: VALID or INVALID)
            if (in_array($transaction->validation_status, [Transaction::VALIDATION_STATUS_VALID, Transaction::VALIDATION_STATUS_INVALID], true)) {
                    Log::debug('Early exit: already terminal status', [
                        'transaction_id' => $transaction->transaction_id,
                        'validation_status' => $transaction->validation_status,
                        'job_status' => $transaction->job_status
                    ]);
                Log::info('Skipping processing – transaction already terminal', [
                    'transaction_id' => $transaction->transaction_id,
                    'validation_status' => $transaction->validation_status
                ]);
                return;
            }

            // Update main record to PROCESSING. ADR-004 D4: job_attempts counts real
            // handle() runs only, so it is incremented exactly once here (after the
            // early exits) and nowhere else in this job.
            $transaction->job_status = Transaction::JOB_STATUS_PROCESSING;
            $transaction->job_attempts = ($transaction->job_attempts ?? 0) + 1;
            if ($transaction->created_at === null) {
                $transaction->created_at = now();
            }
            $transaction->save();

            // Audit job row
            $job = $transaction->jobs()->create([
                'transaction_id' => $transaction->transaction_id, // FK is logical id
                'job_status' => Transaction::JOB_STATUS_PROCESSING,
                'attempt_number' => $this->attempts(),
                'started_at' => now(),
                'created_at' => now(),
            ]);

            // Validation record
            // Validation record
            $validation = $transaction->validations()->create([
                'status_code' => Transaction::VALIDATION_STATUS_PENDING,
                'started_at' => now(),
                'created_at' => now(),
            ]);

            $validationResult = $validationService->validateTransaction($transaction);

            if (empty($validationResult) || !array_key_exists('valid', $validationResult)) {
                throw new Exception('Validation service returned unexpected result shape');
            }

            if (!$validationResult['valid']) {
                $errorsRaw = $validationResult['errors'] ?? ['unknown' => 'Unspecified validation failure'];
                $errorsArray = is_array($errorsRaw) ? $errorsRaw : [$errorsRaw];
                $flatErrors = $this->flattenErrorArray($errorsArray);
                $errorMessage = implode('; ', $flatErrors);

                // ADR-004 D2: a business validation failure is a completed run with an
                // INVALID outcome. INVALID is FK-valid in validation_statuses.
                $validation->update([
                    'status_code' => Transaction::VALIDATION_STATUS_INVALID,
                    'validation_details' => json_encode($errorsArray),
                    'validated_at' => now(),
                ]);
                $job->update([
                    'job_status' => Transaction::JOB_STATUS_COMPLETED,
                    'completed_at' => now(),
                    'last_error' => $errorMessage,
                ]);

                $transaction->validation_status = Transaction::VALIDATION_STATUS_INVALID;
                $transaction->job_status = Transaction::JOB_STATUS_COMPLETED;
                $transaction->last_error = $errorMessage;
                if ($transaction->created_at === null) {
                    $transaction->created_at = now();
                }
                if ($transaction->completed_at === null) {
                    $transaction->completed_at = now();
                }
                    Log::debug('About to save INVALID transaction status', [
                        'transaction_id' => $transaction->transaction_id,
                        'validation_status' => $transaction->validation_status,
                        'job_status' => $transaction->job_status,
                        'completed_at' => $transaction->completed_at,
                        'error_message' => $errorMessage
                    ]);
                $transaction->save();

                Log::warning('Transaction validation failed', [
                    'transaction_id' => $transaction->transaction_id,
                    'errors' => $flatErrors
                ]);

                // Final failure notification (deferred model) - fire only on first terminal transition
                try {
                    // Keep external result payload as 'INVALID' to preserve webhook compatibility
                    $this->dispatchTerminalNotification($transaction, 'INVALID', $flatErrors);
                } catch (\Throwable $notifyEx) {
                    Log::error('Failed sending failure notification', [
                        'transaction_id' => $transaction->transaction_id,
                        'error' => $notifyEx->getMessage(),
                    ]);
                }
                    Log::debug('Early exit: validation failed', [
                        'transaction_id' => $transaction->transaction_id,
                        'validation_status' => $transaction->validation_status,
                        'job_status' => $transaction->job_status,
                        'error_message' => $errorMessage
                    ]);
                return; // No retry here; consider queue retry/backoff policy externally.
            }

            // Success path
            $validation->update([
                'status_code' => Transaction::VALIDATION_STATUS_VALID,
                'details' => 'Validated successfully',
                'completed_at' => now(),
            ]);
            $job->update([
                'job_status' => Transaction::JOB_STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $transaction->validation_status = Transaction::VALIDATION_STATUS_VALID;
            $transaction->job_status = Transaction::JOB_STATUS_COMPLETED;
            $transaction->last_error = null;
            if ($transaction->created_at === null) {
                $transaction->created_at = now();
            }
            if ($transaction->completed_at === null) {
                $transaction->completed_at = now();
            }
                Log::debug('About to save SUCCESS transaction status', [
                    'transaction_id' => $transaction->transaction_id,
                    'validation_status' => $transaction->validation_status,
                    'job_status' => $transaction->job_status,
                    'completed_at' => $transaction->completed_at
                ]);
            $transaction->save();

            Log::info('Transaction processed successfully', [
                'transaction_id' => $transaction->transaction_id,
                'duration_ms' => round((microtime(true) - $started) * 1000, 2)
            ]);

            // Final success notification
            try {
                $this->dispatchTerminalNotification($transaction, 'VALID');
            } catch (\Throwable $notifyEx) {
                Log::error('Failed sending success notification', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $notifyEx->getMessage(),
                ]);
            }

        } catch (\Throwable $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    /**
     * Dispatch terminal webhook notification (simplified to avoid controller dependency).
     */
    protected function dispatchTerminalNotification(Transaction $transaction, string $result, array $errors = []): void
    {
        $terminal = $transaction->terminal; // lazy loads
        if (
            !$terminal ||
            !$terminal->notifications_enabled ||
            empty($terminal->callback_url) ||
            !config('notifications.callbacks.enabled')
        ) {
            \Illuminate\Support\Facades\Log::info('Callback notification suppressed (global callbacks disabled or terminal config)', [
                'transaction_id' => $transaction->transaction_id,
                'terminal_id' => optional($terminal)->id,
                'global_enabled' => config('notifications.callbacks.enabled'),
                'terminal_notifications_enabled' => optional($terminal)->notifications_enabled,
                'has_callback_url' => !empty(optional($terminal)->callback_url),
            ]);
            return; // notifications disabled
        }

        $payload = [
            'transaction_id' => $transaction->transaction_id,
            'terminal_id' => $terminal->id,
            'submission_uuid' => $transaction->submission_uuid,
            'validation_status' => $transaction->validation_status,
            'result' => $result,
            'errors' => $errors,
        ];

        try {
            \Illuminate\Support\Facades\Notification::route('webhook', $terminal->callback_url)
                ->notify(new \App\Notifications\TransactionResultNotification($payload, $result, $errors, $terminal->callback_url));
        } catch (\Throwable $e) {
            Log::error('Terminal notification dispatch failure', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flattens a multi-dimensional array of error messages into a single-level array of strings.
     *
     * This method recursively traverses the input array, extracting all string error messages,
     * regardless of their nesting level. If a nested value is not a string or array, it adds
     * a placeholder message indicating an unknown error format.
     *
     * @param array $errors The array of error messages, potentially nested.
     * @return array A flat array containing all error messages as strings.
     */
    protected function flattenErrorArray($errors)
    {
        $result = [];

        foreach ($errors as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_string($nested)) {
                        $result[] = $nested;
                    } elseif (is_array($nested)) {
                        $result = array_merge($result, $this->flattenErrorArray($nested));
                    } else {
                        $result[] = "Unknown nested error format at $key";
                    }
                }
            } elseif (is_string($value)) {
                $result[] = $value;
            } else {
                $result[] = "Unknown error format at $key";
            }
        }

        return $result;
    }

    // Deprecated legacy validation failure handler removed (handled inline now)

    protected function handleSuccess(): void { /* Deprecated - inline success handling now */ }

    /**
     * Infrastructure failure handler (exception, timeout, MaxAttemptsExceeded via failed()).
     *
     * ADR-004 D3/D4:
     *  - never changes validation_status (a PENDING row stays PENDING);
     *  - sets job_status=FAILED and last_error, and marks the latest non-COMPLETED
     *    transaction_jobs row FAILED;
     *  - never writes 'FAILED' into transaction_validations (PENDING audit rows stay PENDING);
     *  - a terminal row (VALID/INVALID + COMPLETED) is never touched: a late failure is only
     *    logged and counted;
     *  - does not increment job_attempts (counted once per real handle() run).
     *
     * This method must never throw: it runs inside handle()'s catch block before the original
     * exception is rethrown, and from failed().
     */
    protected function handleError(\Throwable $e): void
    {
        try {
            $transaction = Transaction::find($this->transactionId);
            Log::error('Transaction processing failed', [
                'transaction_pk' => $this->transactionId,
                'transaction_id' => optional($transaction)->transaction_id,
                'error' => $e->getMessage(),
            ]);
            if (! $transaction) {
                return;
            }

            // Atomic terminal guard (D4): the FAILED write only applies while the row is not
            // terminal, so a successful run that lands between the read above and this write
            // cannot be downgraded. validation_status is never part of the update (D3).
            $now = $transaction->fromDateTime(now());
            $affected = Transaction::query()
                ->whereKey($transaction->getKey())
                ->where(function ($q) {
                    $q->whereNotIn('validation_status', [Transaction::VALIDATION_STATUS_VALID, Transaction::VALIDATION_STATUS_INVALID])
                        ->orWhereNull('job_status')
                        ->orWhere('job_status', '!=', Transaction::JOB_STATUS_COMPLETED);
                })
                ->update([
                    'job_status' => Transaction::JOB_STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                    'created_at' => DB::raw('COALESCE(created_at, '.DB::getPdo()->quote($now).')'),
                    'completed_at' => DB::raw('COALESCE(completed_at, '.DB::getPdo()->quote($now).')'),
                ]);

            if ($affected === 0) {
                $current = Transaction::query()->whereKey($transaction->getKey())
                    ->first(['id', 'transaction_id', 'tenant_id', 'validation_status', 'job_status']);
                Log::warning('late failure after terminal state ignored', [
                    'transaction_pk' => $transaction->id,
                    'transaction_id' => $transaction->transaction_id,
                    'tenant_id' => $transaction->tenant_id,
                    'validation_status' => optional($current)->validation_status,
                    'job_status' => optional($current)->job_status,
                    'error_class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
                Metrics::incr('transaction_job.late_failure_ignored');

                return;
            }

            // Audit rows are best-effort and only touched once the transaction row was
            // actually marked FAILED.
            try {
                $job = $transaction->jobs()->orderByDesc('id')->first();
                if ($job && $job->job_status !== Transaction::JOB_STATUS_COMPLETED) {
                    $job->update([
                        'job_status' => Transaction::JOB_STATUS_FAILED,
                        'completed_at' => now(),
                        'last_error' => $e->getMessage(),
                    ]);
                }
                $validation = $transaction->validations()->orderByDesc('id')->first();
                if ($validation && $validation->status_code === Transaction::VALIDATION_STATUS_PENDING) {
                    // Keep status_code PENDING (no business outcome); record the error only.
                    $validation->update([
                        'validation_details' => 'Processing error: '.$e->getMessage(),
                    ]);
                }
            } catch (\Throwable $auditEx) {
                Log::error('Failed updating job/validation audit rows after processing failure', [
                    'transaction_pk' => $transaction->id,
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $auditEx->getMessage(),
                ]);
            }

            // The conditional query-builder update bypasses Eloquent events; re-fire
            // 'updated' so the model's write hook (webapp tenant_version bump) still runs
            // as it did with the previous save().
            try {
                $fresh = $transaction->fresh();
                if ($fresh) {
                    Transaction::getEventDispatcher()?->dispatch('eloquent.updated: '.Transaction::class, $fresh);
                }
            } catch (\Throwable $eventEx) {
                Log::error('Failed firing updated event after processing failure', [
                    'transaction_pk' => $transaction->id,
                    'error' => $eventEx->getMessage(),
                ]);
            }
        } catch (\Throwable $handlerEx) {
            Log::error('ProcessTransactionJob failure handler itself failed', [
                'transaction_pk' => $this->transactionId,
                'original_error' => $e->getMessage(),
                'handler_error' => $handlerEx->getMessage(),
            ]);
        }
    }

    /**
     * The job failed to process.
     *
     * @param \Exception $exception
     * @return void
     */
    public function failed(\Throwable $exception): void 
    {
        $this->handleError($exception);
    }

    /**
     * Horizon tags for observability and filtering.
     * Attempts a lightweight lookup to include tenant/terminal; falls back gracefully.
     */
    public function tags(): array
    {
        try {
            $tx = Transaction::select('id', 'tenant_id', 'terminal_id', 'transaction_id')
                ->find($this->transactionId);
            if ($tx) {
                return [
                    'domain:processing',
                    'transaction:pk=' . $tx->id,
                    'transaction:id=' . ($tx->transaction_id ?? 'unknown'),
                    'tenant:' . ($tx->tenant_id ?? 'unknown'),
                    'terminal:' . ($tx->terminal_id ?? 'unknown'),
                ];
            }
        } catch (\Throwable $e) {
            // ignore and fall through
        }
        return [
            'domain:processing',
            'transaction:pk=' . $this->transactionId,
        ];
    }

    /**
     * Ensure no overlapping runs for the same transaction pk across workers.
     */
    public function middleware(): array
    {
        return [
            // Prevent concurrent processing for the same transaction id
            (new WithoutOverlapping('txn:'.$this->transactionId))
                ->releaseAfter(10)   // requeue after 10s if lock held
                ->expireAfter(600),  // safety expiry 10 minutes
        ];
    }

    
}