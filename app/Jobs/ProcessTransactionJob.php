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
        Log::info('Processing transaction', [
            'transaction_id' => $this->transaction->id,
            'attempt' => $this->attempts()
        ]);

        try {
            // Update transaction status to processing
            $this->transaction->update([
                'job_status' => 'PROCESSING',
            ]);

            // Validate the transaction
            $validationResult = $validationService->validateTransaction($this->transaction);

            if (!$validationResult['valid']) {
                // Format error messages into a readable string for better logging/display
                $errorMessages = is_array($validationResult['errors']) ? 
                    implode('; ', $this->flattenErrorArray($validationResult['errors'])) : 
                    $validationResult['errors'];

                // Record validation errors
                $this->transaction->update([
                    'job_status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'last_error' => $errorMessages,
                    'job_attempts' => $this->transaction->job_attempts + 1
                ]);

                Log::error('Transaction validation failed', [
                    'transaction_id' => $this->transaction->id,
                    'errors' => $validationResult['errors']
                ]);

                // If we've reached max attempts or errors are not recoverable, mark as permanently failed
                if ($this->attempts() >= $this->maxAttempts || $this->hasNonRecoverableErrors($validationResult['errors'])) {
                    $this->transaction->update([
                        'job_status' => 'FAILED',
                        'validation_status' => 'INVALID'
                    ]);

                    // Trigger event for permanent failure if needed
                    try {
                        event(new \App\Events\TransactionPermanentlyFailed($this->transaction));
                    } catch (\Exception $eventError) {
                        Log::error('Failed to fire TransactionPermanentlyFailed event', [
                            'error' => $eventError->getMessage(),
                            'transaction_id' => $this->transaction->id
                        ]);
                    }

                    return;
                }

                // Otherwise, throw exception to trigger retry
                throw new \Exception('Validation failed: ' . $errorMessages);
            }

            // If validation passes, mark as completed
            $this->transaction->update([
                'job_status' => 'COMPLETED',
                'validation_status' => 'VALID',
                'last_error' => null,
                'completed_at' => now()
            ]);

            Log::info('Transaction processed successfully', [
                'transaction_id' => $this->transaction->id
            ]);

        } catch (\Exception $e) {
            // Update failure count and error message
            $this->transaction->update([
                'job_status' => 'FAILED',
                'last_error' => $e->getMessage(),
                'job_attempts' => $this->transaction->job_attempts + 1
            ]);

            Log::error('Transaction processing error', [
                'transaction_id' => $this->transaction->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // Rethrow to trigger retry
            throw $e;
        }
    }

    /**
     * Flatten potentially nested error arrays into a simple array of strings
     *
     * @param array $errors
     * @return array
     */
    protected function flattenErrorArray($errors)
    {
        $result = [];
        
        foreach ($errors as $key => $value) {
            if (is_array($value)) {
                // If it's an array of errors, add each one
                foreach ($value as $nestedValue) {
                    if (is_string($nestedValue)) {
                        $result[] = $nestedValue;
                    }
                }
            } else {
                // If it's a simple key-value pair, add the value
                $result[] = is_string($value) ? $value : "Error in {$key}";
            }
        }
        
        return $result;
    }

    /**
     * Determine if validation errors are non-recoverable
     *
     * @param array $errors
     * @return bool
     */
    protected function hasNonRecoverableErrors(array $errors)
    {
        $nonRecoverablePatterns = [
            'duplicate',
            'invalid promo code',
            'outside of store operating hours',
            'tax exempt',
            'terminal is not active',
            'transaction would exceed daily limit'
        ];

        foreach ($errors as $error) {
            foreach ($nonRecoverablePatterns as $pattern) {
                if (stripos($error, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The job failed to process.
     *
     * @param \Exception $exception
     * @return void
     */
    public function failed(\Exception $exception)
    {
        Log::error('Transaction job failed', [
            'transaction_id' => $this->transaction->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->transaction->update([
            'job_status' => 'FAILED',
            'last_error' => $exception->getMessage()
        ]);
    }
}