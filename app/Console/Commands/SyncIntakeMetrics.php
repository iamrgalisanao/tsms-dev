<?php

namespace App\Console\Commands;

use App\Models\TransactionIntake;
use App\Support\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncIntakeMetrics extends Command
{
    protected $signature = 'tsms:metrics-sync';
    protected $description = 'Synchronize Redis metrics with the actual database record counts.';

    public function handle()
    {
        $this->info('Starting metrics synchronization...');

        $processed = TransactionIntake::whereIn('processing_status', [
            TransactionIntake::PROCESSING_STATUS_PROCESSED,
            TransactionIntake::PROCESSING_STATUS_DUPLICATE
        ])->count();

        $failed = TransactionIntake::where('processing_status', TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT)->count();

        $this->line("Database Counts -> Processed: $processed | Failed: $failed");

        // Force set the cache keys
        Cache::forever('metrics.intake.processed_count', $processed);
        Cache::forever('metrics.intake.failed_count', $failed);

        $this->success('Metrics synchronized successfully.');
    }

    protected function success($message)
    {
        $this->output->writeln("<info>✔</info> $message");
    }
}
