<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Services\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RetryTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transactionId;
    protected $terminalId;
    protected $attempt;
    protected $maxAttempts;
    protected $backoff;

    /**
     * Create a new job instance.
     *
     * @param  string  $transactionId
     * @param  int  $terminalId
     * @param  int  $attempt
     * @return void
     */
    public function __construct($transactionId, $terminalId, $attempt = 1)
    {
        $this->transactionId = $transactionId;
        $this->terminalId = $terminalId;
        $this->attempt = $attempt;
        $this->maxAttempts = config('retry.max_attempts', 3);
        $this->backoff = config('retry.delay', 60);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Check circuit breaker status
        $circuitBreaker = new CircuitBreaker('transaction_service');
        if (!$circuitBreaker->isAvailable()) {
            Log::warning('Retry skipped due to open circuit breaker', [
                'transaction_id' => $this->transactionId,
                'terminal_id' => $this->terminalId
            ]);
            return;
        }

        // Find the transaction using new normalized schema if available
        // Optionally, update TransactionJob status for retry attempts
        $transactionJob = null;
        if (class_exists('App\\Models\\TransactionJob')) {
            $transactionJob = \App\Models\TransactionJob::where('transaction_id', $this->transactionId)
                ->where('terminal_id', $this->terminalId)
                ->latest('created_at')->first();
            if ($transactionJob) {
                $transactionJob->update([
                    'status' => 'RETRYING',
                    'attempt_number' => $this->attempt,
                    'started_at' => now(),
                ]);
            }
        }

        // Find the transaction
        $log = IntegrationLog::where('transaction_id', $this->transactionId)
            ->where('terminal_id', $this->terminalId)
            ->first();

        if (!$log) {
            Log::error('Retry failed - transaction not found', [
                'transaction_id' => $this->transactionId,
                'terminal_id' => $this->terminalId
            ]);
            return;
        }

        // Implement exponential backoff for Feature #4
        $backoffDelay = $this->backoff * pow(config('retry.backoff_multiplier', 2), $this->attempt - 1);

        Log::info('Attempting transaction retry', [
            'transaction_id' => $this->transactionId,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'backoff_delay' => $backoffDelay
        ]);

        // Attempt to retry the transaction
        try {
            $startTime = microtime(true);
            
            // Get the POS terminal information
            $terminal = PosTerminal::where('id', $this->terminalId)->first();
            
            if (!$terminal) {
                Log::error('Retry failed - POS terminal not found', [
                    'terminal_id' => $this->terminalId
                ]);
                return;
            }
            
            // Make the actual API call to retry the transaction
            // This would typically call the payment processor or other service
            $response = Http::timeout(30)
                ->post(config('services.payment_gateway.url') . '/process', [
                    'transaction_id' => $this->transactionId,
                    'terminal_uid' => $terminal->terminal_uid, // Use the terminal_uid field from PosTerminal
                    'retry_attempt' => $this->attempt
                ]);
            
            $responseTime = microtime(true) - $startTime;
            
            // Record retry attempt results
            $success = $response->successful();
            $log->retry_count = $this->attempt;
            $log->last_retry_at = now();
            $log->response_time = $responseTime;
            $log->retry_success = $success;
            
            if ($success) {
                $log->status = 'SUCCESS';
                if ($transactionJob) {
                    $transactionJob->update([
                        'status' => 'COMPLETED',
                        'completed_at' => now(),
                    ]);
                }
                $circuitBreaker->recordSuccess();
                Log::info('Transaction retry successful', [
                    'transaction_id' => $this->transactionId,
                    'attempt' => $this->attempt
                ]);
            } else {
                $log->retry_reason = $response->body() ?? 'Unknown error';
                if ($transactionJob) {
                    $transactionJob->update([
                        'status' => 'FAILED',
                        'completed_at' => now(),
                        'error_message' => $log->retry_reason,
                    ]);
                }
                $circuitBreaker->recordFailure();
                
                // Schedule another retry if we haven't hit the max attempts
                if ($this->attempt < $this->maxAttempts) {
                    $this->release($backoffDelay);
                    Log::info('Scheduling another retry attempt', [
                        'transaction_id' => $this->transactionId,
                        'next_attempt' => $this->attempt + 1,
                        'delay' => $backoffDelay
                    ]);
                    
                    // Create a new job for the next attempt
                    dispatch(new RetryTransactionJob(
                        $this->transactionId,
                        $this->terminalId,
                        $this->attempt + 1
                    ))->delay(now()->addSeconds($backoffDelay));
                } else {
                    $log->status = 'FAILED';
                    Log::warning('Max retry attempts reached, giving up', [
                        'transaction_id' => $this->transactionId,
                        'max_attempts' => $this->maxAttempts
                    ]);
                }
            }
            
            $log->save();
            
        } catch (\Exception $e) {
            Log::error('Exception during transaction retry', [
                'transaction_id' => $this->transactionId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempt
            ]);
            $circuitBreaker->recordFailure();
            if ($transactionJob) {
                $transactionJob->update([
                    'status' => 'FAILED',
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }
            
            // Update the log with failure information
            $log->retry_count = $this->attempt;
            $log->last_retry_at = now();
            $log->retry_reason = $e->getMessage();
            $log->retry_success = false;
            $log->save();
            
            // Schedule another retry if we haven't hit the max attempts
            if ($this->attempt < $this->maxAttempts) {
                dispatch(new RetryTransactionJob(
                    $this->transactionId,
                    $this->terminalId,
                    $this->attempt + 1
                ))->delay(now()->addSeconds($backoffDelay));
            }
        }
    }
}