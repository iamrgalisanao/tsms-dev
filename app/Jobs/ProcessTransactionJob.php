<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Exception;

class ProcessTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Primary key ID of the transaction (we re-query for freshest state & lean payload).
     */
    protected int $transactionId;

    /** Max processing attempts (framework also governs retries). */
    protected int $maxAttempts = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
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
        $lockKey = "txn:process:" . $this->transactionId;
        // Prevent parallel processing (5s lock safeguard)
        $lock = Cache::lock($lockKey, 5);
        if (!$lock->get()) {
            Log::warning('Skipping transaction processing due to active lock', [
                'transaction_pk' => $this->transactionId,
                'attempt' => $this->attempts()
            ]);
            return;
        }

        $started = microtime(true);
        try {
            $transaction = Transaction::with([])->find($this->transactionId);
            if (!$transaction) {
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

            // Short‑circuit if already processed / terminal
            if (in_array($transaction->validation_status, [Transaction::VALIDATION_STATUS_VALID, Transaction::VALIDATION_STATUS_FAILED])) {
                Log::info('Skipping processing – transaction already terminal', [
                    'transaction_id' => $transaction->transaction_id,
                    'validation_status' => $transaction->validation_status
                ]);
                return;
            }

            // Update main record to PROCESSING
            $transaction->job_status = Transaction::JOB_STATUS_PROCESSING;
            $transaction->save();

            // Audit job row
            $job = $transaction->jobs()->create([
                'transaction_id' => $transaction->transaction_id, // FK is logical id
                'job_status' => Transaction::JOB_STATUS_PROCESSING,
                'attempt_number' => $this->attempts(),
                'started_at' => now(),
            ]);

            // Validation record
            $validation = $transaction->validations()->create([
                'status_code' => Transaction::VALIDATION_STATUS_PENDING,
                'started_at' => now(),
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

                $validation->update([
                    'status_code' => Transaction::VALIDATION_STATUS_FAILED,
                    'details' => json_encode($errorsArray),
                    'completed_at' => now(),
                ]);
                $job->update([
                    'job_status' => Transaction::JOB_STATUS_FAILED,
                    'completed_at' => now(),
                    'error_message' => $errorMessage,
                ]);

                $transaction->validation_status = Transaction::VALIDATION_STATUS_FAILED;
                $transaction->job_status = Transaction::JOB_STATUS_FAILED;
                $transaction->last_error = $errorMessage;
                $transaction->job_attempts = ($transaction->job_attempts ?? 0) + 1;
                $transaction->completed_at = now();
                $transaction->save();

                Log::warning('Transaction validation failed', [
                    'transaction_id' => $transaction->transaction_id,
                    'errors' => $flatErrors
                ]);

                // Final failure notification (deferred model) - fire only on first terminal transition
                try {
                    $this->dispatchTerminalNotification($transaction, 'INVALID', $flatErrors);
                } catch (\Throwable $notifyEx) {
                    Log::error('Failed sending failure notification', [
                        'transaction_id' => $transaction->transaction_id,
                        'error' => $notifyEx->getMessage(),
                    ]);
                }
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
            $transaction->job_attempts = ($transaction->job_attempts ?? 0) + 1;
            $transaction->completed_at = now();
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
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Dispatch terminal webhook notification (simplified to avoid controller dependency).
     */
    protected function dispatchTerminalNotification(Transaction $transaction, string $result, array $errors = []): void
    {
        $terminal = $transaction->terminal; // lazy loads
        if (!$terminal || !$terminal->notifications_enabled || empty($terminal->callback_url)) {
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

    protected function handleError(\Throwable $e): void
    {
        $transaction = Transaction::find($this->transactionId);
        Log::error('Transaction processing failed', [
            'transaction_pk' => $this->transactionId,
            'transaction_id' => optional($transaction)->transaction_id,
            'error' => $e->getMessage(),
        ]);
        if ($transaction) {
            // Update latest job & validation audit rows if exist
            $job = $transaction->jobs()->latest()->first();
            if ($job && $job->job_status !== Transaction::JOB_STATUS_COMPLETED) {
                $job->update([
                    'job_status' => Transaction::JOB_STATUS_FAILED,
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }
            $validation = $transaction->validations()->latest()->first();
            if ($validation && $validation->status_code === Transaction::VALIDATION_STATUS_PENDING) {
                $validation->update([
                    'status_code' => Transaction::VALIDATION_STATUS_FAILED,
                    'details' => $e->getMessage(),
                    'completed_at' => now(),
                ]);
            }
            $transaction->job_status = Transaction::JOB_STATUS_FAILED;
            if ($transaction->validation_status !== Transaction::VALIDATION_STATUS_VALID) {
                $transaction->validation_status = Transaction::VALIDATION_STATUS_FAILED;
            }
            $transaction->last_error = $e->getMessage();
            $transaction->job_attempts = ($transaction->job_attempts ?? 0) + 1;
            $transaction->completed_at = now();
            $transaction->save();
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
}