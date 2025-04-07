<?php
namespace App\Console\Commands;

use App\Models\IntegrationLog;
use App\Jobs\RetryTransactionJob;
use Illuminate\Console\Command;

class DispatchRetryTransactions extends Command
{
    protected $signature = 'transactions:retry-dispatch';
    protected $description = 'Dispatch retry jobs for failed transactions';

    /**
     * Handle the command to dispatch retry jobs for failed transactions.
     *
     * This method retrieves failed transactions from the `IntegrationLog` table
     * that meet the following criteria:
     * - Status is 'FAILED'.
     * - Retry count is less than the maximum allowed retries (default is 3).
     * - The next retry time is less than or equal to the current time.
     *
     * If no eligible transactions are found, it logs an informational message
     * and exits. Otherwise, it dispatches a retry job for each eligible transaction
     * and logs the total number of dispatched jobs.
     *
     * @return void
     */
    public function handle()
    {
        $maxRetries = config('tsms.max_retries', 3);

        $logs = IntegrationLog::where('status', 'FAILED')
            ->where('retry_count', '<', $maxRetries)
            ->where('next_retry_at', '<=', now())
            ->get();

        if ($logs->isEmpty()) {
            $this->info('No eligible failed transactions for retry.');
            return;
        }

        foreach ($logs as $log) {
            RetryTransactionJob::dispatch($log);
        }

        $this->info("Dispatched {$logs->count()} retry jobs.");
        
    }
}

