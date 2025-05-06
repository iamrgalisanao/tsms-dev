<?php


namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use App\Jobs\CheckCircuitBreakerStatus;

class ScheduleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            
            $schedule->job(new CheckCircuitBreakerStatus)
                ->everyFiveMinutes()
                ->name('check-circuit-breakers')
                ->withoutOverlapping();
        });
    }
}