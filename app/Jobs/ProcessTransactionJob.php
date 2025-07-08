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

    protected $transaction;
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
     * Execute the job.
     *
     * @return void
     */
     public function handle(TransactionValidationService $validationService)
    {
        Log::debug('Starting transaction processing', [
            'transaction_id' => $this->transaction->id,
            'attempt' => $this->attempts(),
            'transaction_data' => $this->transaction->toArray()
        ]);

        try {
            $this->transaction->refresh();

            // Create or update TransactionJob record for this attempt
            $job = $this->transaction->jobs()->create([
                'status' => 'PROCESSING',
                'attempt_number' => $this->attempts(),
                'started_at' => now(),
            ]);

            // Create TransactionValidation record
            $validation = $this->transaction->validations()->create([
                'status' => 'PENDING',
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
                    'status' => 'ERROR',
                    'details' => $errorMessages,
                    'completed_at' => now(),
                ]);
                $job->update([
                    'status' => 'FAILED',
                    'completed_at' => now(),
                ]);
                throw new Exception("Validation failed: " . $errorMessages);
            }

            // Mark as COMPLETED if validation passed
            $validation->update([
                'status' => 'VALID',
                'details' => 'Validated successfully',
                'completed_at' => now(),
            ]);
            $job->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
            ]);

        } catch (\Throwable $e) {
            $this->handleError($e);
            throw $e; 
        }
    }

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
        $this->transaction->update([
            'job_status' => 'COMPLETED',
            'validation_status' => 'VALID',
            'last_error' => null,
            'completed_at' => now()
        ]);

        Log::info('Transaction processed successfully', [
            'transaction_id' => $this->transaction->id
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
                'status' => 'FAILED',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
        $validation = $this->transaction->validations()->latest()->first();
        if ($validation) {
            $validation->update([
                'status' => 'ERROR',
                'details' => $e->getMessage(),
                'completed_at' => now(),
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