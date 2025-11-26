<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

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

        // The RefreshHourlyWindowJob has been removed. This command is now a no-op.
        $this->info('RefreshHourlyWindowJob has been removed — reporting:dispatch will not dispatch jobs.');
        return 0;

        return 0;
    }
}
