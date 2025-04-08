<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IntegrationLog;
use App\Jobs\RetryTransactionJob;

class TestRetryTransaction extends Command
{
    protected $signature = 'transactions:test-retry';
    protected $description = 'Test dispatching retry job for the latest failed transaction';

    public function handle(): void
    {
        $log = IntegrationLog::where('status', 'FAILED')->latest()->first();

        if (!$log) {
            $this->warn('❌ No failed transactions found to retry.');
            return;
        }

        dispatch(new RetryTransactionJob($log));

        $this->info("✅ Dispatched retry job for IntegrationLog ID: {$log->id}");
    }
}
