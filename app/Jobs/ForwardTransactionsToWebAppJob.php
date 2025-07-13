<?php

namespace App\Jobs;

use App\Services\WebAppForwardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ForwardTransactionsToWebAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // Set specific queue for webapp forwarding
        $this->onQueue('webapp-forwarding');
    }

    /**
     * Execute the job.
     */
    public function handle(WebAppForwardingService $forwardingService): void
    {
        Log::info('Starting WebApp transaction forwarding job');

        try {
            $result = $forwardingService->forwardUnsentTransactions();

            if ($result['success']) {
                Log::info('WebApp forwarding job completed successfully', [
                    'forwarded_count' => $result['forwarded_count'],
                    'reason' => $result['reason'] ?? 'bulk_forwarding'
                ]);
            } else {
                Log::warning('WebApp forwarding job completed with issues', [
                    'reason' => $result['reason'] ?? 'unknown',
                    'error' => $result['error'] ?? 'No specific error'
                ]);

                // Don't fail the job for circuit breaker or no transactions
                if (in_array($result['reason'] ?? '', ['circuit_breaker_open', 'no_transactions'])) {
                    return;
                }

                // Fail the job for other errors to trigger retry
                throw new \Exception('WebApp forwarding failed: ' . ($result['error'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            Log::error('WebApp forwarding job failed', [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries
            ]);

            // Re-throw to trigger job retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('WebApp forwarding job failed permanently', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Optional: Send notification to administrators
        // You could dispatch a notification job here
    }
}
