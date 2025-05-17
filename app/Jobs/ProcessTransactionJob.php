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
            Log::info('Processing transaction', [
                'transaction_id' => $this->transaction->transaction_id,
                'tenant_id' => $this->transaction->tenant_id,
                'attempt' => $this->attempts()
            ]);
            
            // Check circuit breaker status
            $circuitBreaker = new CircuitBreaker('transaction_processing');
            if (!$circuitBreaker->isAvailable()) {
                Log::warning('Circuit breaker is open, delaying transaction processing', [
                    'transaction_id' => $this->transaction->transaction_id
                ]);
                
                // Release the job back to the queue with a delay
                $this->release(30); // 30 seconds delay
                return;
            }
            
            // Process transaction logic here
            // For now, we'll just simulate successful processing
            $success = true;
            
            if ($success) {
                // Update transaction status
                $this->transaction->processing_status = 'completed';
                $this->transaction->processed_at = now();
                $this->transaction->save();
                
                Log::info('Transaction processed successfully', [
                    'transaction_id' => $this->transaction->transaction_id
                ]);
            } else {
                throw new \Exception('Transaction processing failed');
            }
        } catch (\Exception $e) {
            Log::error('Error processing transaction', [
                'transaction_id' => $this->transaction->transaction_id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);
            
            // Update transaction status
            $this->transaction->processing_status = 'failed';
            $this->transaction->error_message = $e->getMessage();
            $this->transaction->save();
            
            // Record the failure with the circuit breaker
            $circuitBreaker = new CircuitBreaker('transaction_processing');
            $circuitBreaker->recordFailure();
            
            // Throw the exception to trigger retry
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
}