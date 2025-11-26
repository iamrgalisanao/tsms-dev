<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use App\Jobs\Reporting\RefreshHourlyWindowJob;

class ReportingDispatchCommand extends Command
{
    protected $signature = 'reporting:dispatch {--minutes=15} {--chunk=5}';

    protected $description = 'Dispatch incremental reporting refresh jobs split into chunk windows';

    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        $chunk = (int) $this->option('chunk');
        $minutes = max(1, $minutes);
        $chunk = max(1, min($chunk, $minutes));

        $now = Carbon::now();
        $start = $now->copy()->subMinutes($minutes);

        $windows = [];
        while ($start->lt($now)) {
            $end = $start->copy()->addMinutes($chunk);
            if ($end->gt($now)) { $end = $now->copy(); }
            $windows[] = [$start->toIso8601String(), $end->toIso8601String()];
            $start = $end;
        }

        foreach ($windows as [$from, $to]) {
            // Check runtime flag to disable dispatching of refresh jobs
            try {
                if (filter_var(env('DISABLE_REFRESH_HOURLY_WINDOW_JOB', 'true'), FILTER_VALIDATE_BOOLEAN)) {
                    $this->info('RefreshHourlyWindowJob dispatching is disabled via DISABLE_REFRESH_HOURLY_WINDOW_JOB; no jobs were dispatched.');
                    return 0;
                }
            } catch (\Throwable $e) {
                // If env lookup fails, fall through and dispatch as before
            }

            // Dispatch a job per window; queue name set in job constructor
            Bus::dispatch(new RefreshHourlyWindowJob($from, $to));
            $this->info("Dispatched reporting window $from -> $to");
        }

        return 0;
    }
}
