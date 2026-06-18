<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class DispatchHorizonTestBatch extends Command
{
    protected $signature = 'horizon:test-batch {--jobs=3 : Number of tiny jobs to include in the batch}';
    protected $description = 'Dispatch a small batch for Horizon UI smoke testing';

    public function handle(): int
    {
        $jobsCount = (int) $this->option('jobs');
        $jobs = [];
        for ($i = 0; $i < max(1, $jobsCount); $i++) {
            $jobs[] = function () use ($i) {
                // simulate very small work
                usleep(10000); // 10ms
            };
        }

        $batch = Bus::batch($jobs)
            ->name('Horizon UI Smoke — '.now()->toDateTimeString())
            ->dispatch();

        $this->info('Dispatched batch: '.$batch->id.' ('.$jobsCount.' jobs)');
        return self::SUCCESS;
    }
}
