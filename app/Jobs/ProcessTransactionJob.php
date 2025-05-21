<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\JobProcessingService;

class ProcessTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transaction;
    
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;
    
    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [2, 4, 8]; // Exponential backoff

    /**
     * Create a new job instance.
     *
     * @param Transaction $transaction
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
    public function handle()
    {
        try {
            // Initialize job status
            $this->transaction->update([
                'job_status' => Transaction::JOB_STATUS_PROCESSING,
                'job_attempts' => $this->attempts(),
                'validation_status' => 'VALIDATING'
            ]);

            Log::info('Started transaction processing', [
                'transaction_id' => $this->transaction->transaction_id,
                'attempt' => $this->attempts(),
                'tenant_id' => $this->transaction->tenant_id
            ]);

            // Check circuit breaker
            if (!$this->isCircuitBreakerAvailable()) {
                Log::warning('Circuit breaker is open, delaying transaction processing', [
                    'transaction_id' => $this->transaction->transaction_id
                ]);
                
                // Release the job back to the queue with a delay
                $this->release(30); // 30 seconds delay
                return;
            }

            // Process transaction logic here
            $success = $this->processTransaction();

            if ($success) {
                // Mark as completed
                $this->transaction->update([
                    'job_status' => Transaction::JOB_STATUS_COMPLETED,
                    'validation_status' => 'COMPLETED',
                    'completed_at' => now()
                ]);

                Log::info('Transaction processed successfully', [
                    'transaction_id' => $this->transaction->transaction_id,
                    'processing_time' => now()->diffInMilliseconds($this->transaction->created_at)
                ]);
            } else {
                throw new \Exception('Transaction processing failed');
            }
        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Transaction processing failed after max retries', [
            'transaction_id' => $this->transaction->transaction_id,
            'error' => $exception->getMessage()
        ]);
        
        // Update transaction with failure details
        $this->transaction->processing_status = 'failed';
        $this->transaction->error_message = $exception->getMessage();
        $this->transaction->retry_count = $this->attempts();
        $this->transaction->save();
        
        // Dispatch retry job if needed (from RetryHistory module)
        if (class_exists('App\Jobs\RetryTransactionJob')) {
            RetryTransactionJob::dispatch($this->transaction->id)
                ->delay(now()->addMinutes(5));
        }
    }

    /**
     * Check if the circuit breaker is available.
     *
     * @return bool
     */
    private function isCircuitBreakerAvailable()
    {
        $circuitBreaker = new CircuitBreaker('transaction_processing');
        return $circuitBreaker->isAvailable();
    }

    /**
     * Handle transaction processing failure.
     *
     * @param \Exception $e
     * @return void
     */
    private function handleFailure(\Exception $e)
    {
        Log::error('Error processing transaction', [
            'transaction_id' => $this->transaction->transaction_id,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts()
        ]);

        // Update transaction status
        $this->transaction->update([
            'job_status' => Transaction::JOB_STATUS_FAILED,
            'error_message' => $e->getMessage()
        ]);

        // Record the failure with the circuit breaker
        $circuitBreaker = new CircuitBreaker('transaction_processing');
        $circuitBreaker->recordFailure();
    }

    /**
     * Handle job failure.
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleJobFailure(\Exception $e)
    {
        Log::error('Transaction processing failed', [
            'transaction_id' => $this->transaction->transaction_id,
            'error' => $e->getMessage()
        ]);

        $this->transaction->update([
            'job_status' => Transaction::JOB_STATUS_FAILED,
            'last_error' => $e->getMessage(),
            'job_attempts' => $this->attempts()
        ]);
    }

    /**
     * Process the transaction.
     *
     * @return bool
     */
    private function processTransaction()
    {
        $service = app(JobProcessingService::class); // Fix typo in class name
        return $service->processTransaction($this->transaction);
    }
}