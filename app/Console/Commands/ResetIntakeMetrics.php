<?php

namespace App\Console\Commands;

use App\Support\Metrics;
use Illuminate\Console\Command;

class ResetIntakeMetrics extends Command
{
    protected $signature = 'tsms:metrics-reset {--all : Reset all metrics including historical buckets}';
    protected $description = 'Reset the intake pipeline metrics for a clean slate dashboard.';

    public function handle()
    {
        $this->info('Resetting TSMS intake metrics...');

        $metrics = [
            'intake.received_count',
            'intake.accepted_count',
            'intake.rejected_count',
            'intake.processed_count',
            'intake.failed_count',
            'intake.dispatch_latency',
            'intake.processing_lag',
            'intake.worker_time',
        ];

        Metrics::reset($metrics);

        if ($this->option('all')) {
            $this->warn('Historical buckets for charts are NOT cleared by this command (they utilize time-based keys).');
        }

        $this->success('Metrics reset complete. Your dashboard should now reflect 0% fail rate until new data arrives.');
    }

    protected function success($message)
    {
        $this->output->writeln("<info>✔</info> $message");
    }
}
