<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\TransactionValidationService;
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
     * The transaction instance to be processed by the job.
     *
     * @var mixed
     */
     protected $transaction;
    /**
     * The maximum number of attempts to process the transaction.
     *
     * @var int
     */
    
    protected $maxAttempts = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
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
        Log::debug('Starting transaction processing', [
            'transaction_id' => is_object($this->transaction) ? $this->transaction->id : $this->transaction,
            'attempt' => $this->attempts(),
            'transaction_data' => is_object($this->transaction) ? $this->transaction->toArray() : ['transaction_id' => $this->transaction]
        ]);

        try {
            $this->transaction->refresh();

            // Create or update TransactionJob record for this attempt
            $job = $this->transaction->jobs()->create([
                'job_status' => 'PROCESSING',
                'attempt_number' => $this->attempts(),
                'started_at' => now(),
            ]);

            // Create TransactionValidation record
            Log::debug('Creating TransactionValidation', [
                'status_code' => 'PENDING',
                'transaction_id' => $this->transaction->id
            ]);
            $validation = $this->transaction->validations()->create([
                'status_code' => 'PENDING',
                'started_at' => now(),
            ]);

            // Run validation
            $validationResult = $validationService->validateTransaction($this->transaction);

            if (!$validationResult['valid']) {
                $errors = is_array($validationResult['errors']) 
                    ? $validationResult['errors'] 
                    : [$validationResult['errors']];
                $errorMessages = implode('; ', $this->flattenErrorArray($errors));

                $validation->update([
                    'status_code' => 'ERROR',
                    'details' => $errorMessages,
                    'completed_at' => now(),
                ]);
                Log::debug('Updating TransactionValidation', [
                    'status_code' => 'ERROR',
                    'transaction_id' => $this->transaction->id,
                    'details' => $errorMessages
                ]);
                $job->update([
                    'job_status' => 'FAILED',
                    'completed_at' => now(),
                ]);
                throw new Exception("Validation failed: " . $errorMessages);
            }

            // Mark as COMPLETED if validation passed
            $validation->update([
                'status_code' => 'VALID',
                'details' => 'Validated successfully',
                'completed_at' => now(),
            ]);
            Log::debug('Updating TransactionValidation', [
                'status_code' => 'VALID',
                'transaction_id' => $this->transaction->id
            ]);
            $job->update([
                'job_status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

            // Update main transaction status to VALID
            $this->handleSuccess();

        } catch (\Throwable $e) {
            $this->handleError($e);
            throw $e; 
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

    protected function handleValidationFailure(array $validationResult): void
    {
        $errorMessages = is_array($validationResult['errors']) ? 
            implode('; ', Arr::flatten((array)$validationResult['errors'])) : 
            $validationResult['errors'];

        $this->transaction->update([
            'job_status' => 'FAILED',
            'validation_status' => 'INVALID',
            'validation_details' => json_encode($validationResult['errors']),
            'last_error' => $errorMessages,
            'completed_at' => now(),
            'job_attempts' => $this->attempts()
        ]);

        if ($this->attempts() >= $this->maxAttempts) {
            Log::error('Max attempts reached', [
                'transaction_id' => $this->transaction->id,
                'errors' => $validationResult['errors']
            ]);
            return;
        }

        $this->release(30); // Retry after 30 seconds
    }

    protected function handleSuccess(): void
    {
        Log::info('handleSuccess called', [
            'transaction_id' => $this->transaction->id,
            'old_status' => $this->transaction->validation_status
        ]);

        $this->transaction->update([
            'job_status' => 'COMPLETED',
            'validation_status' => 'VALID',
            'last_error' => null,
            'completed_at' => now()
        ]);

        // Refresh the model to get the latest value from DB
        $this->transaction->refresh();

        Log::info('Transaction processed successfully', [
            'transaction_id' => $this->transaction->id,
            'new_status' => $this->transaction->validation_status
        ]);
    }

    protected function handleError(\Throwable $e): void
    {
        Log::error('Transaction processing failed', [
            'transaction_id' => $this->transaction->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        // Update the latest job and validation as failed
        $job = $this->transaction->jobs()->latest()->first();
        if ($job) {
            $job->update([
                'job_status' => 'FAILED',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
        $validation = $this->transaction->validations()->latest()->first();
        if ($validation) {
            $validation->update([
                'status_code' => 'ERROR',
                'details' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            Log::debug('Updating TransactionValidation in handleError', [
                'status_code' => 'ERROR',
                'transaction_id' => $this->transaction->id,
                'details' => $e->getMessage()
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
}