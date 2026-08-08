<?php

namespace App\Jobs;

use App\Models\CircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Contracts\Silenced;
use Illuminate\Support\Facades\Redis;

class CheckCircuitBreakerStatus implements ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;

    public function __construct()
    {
        // NOTE: cannot use `public $queue = 'low';` here — Queueable already
        // declares an untyped `public $queue;` (default null), and PHP 8.4
        // treats a class re-declaring a trait property with a different
        // default value as an incompatible composition (hard fatal error at
        // class-load time), not merely a silent override as in older PHP
        // versions. `onQueue()` in the constructor is an equally explicit,
        // approved assignment mechanism (see T046a in
        // specs/001-100-tenant-resilience/tasks.md) that avoids this clash.
        $this->onQueue('low');
    }

    public function handle(): void
    {
        $updated = CircuitBreaker::query()
            ->where('status', 'OPEN')
            ->where('cooldown_until', '<=', now())
            ->update([
                'status' => 'HALF_OPEN',
                'trip_count' => 0
            ]);

        // Track metrics in Redis for Horizon
        Redis::throttle('circuit-breaker-metrics')
            ->allow(1)
            ->every(5)
            ->then(function () use ($updated) {
                Redis::hincrby('circuit-breaker:stats', 'auto_resets', $updated);
            });
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['circuit-breaker', 'maintenance'];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5);
    }
}