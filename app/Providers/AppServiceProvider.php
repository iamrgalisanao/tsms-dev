<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Define auth rate limiter
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinutes(
                config('rate-limiting.default_limits.auth.decay_minutes', 15),
                config('rate-limiting.default_limits.auth.attempts', 5)
            )->by($request->ip());
        });

        // Define API rate limiter
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(
                config('rate-limiting.default_limits.api.attempts', 60)
            )->by($request->user()?->id ?: $request->ip());
        });

        // Define circuit breaker rate limiter
        RateLimiter::for('circuit-breaker', function (Request $request) {
            $tenantKey = $request->user()?->id . '|' . $request->header('X-Tenant-ID');
            return [
                Limit::perMinute(
                    config('rate-limiting.default_limits.circuit_breaker.attempts', 30)
                )->by($tenantKey),
                Limit::perMinute(60)->by($request->ip())
            ];
        });
    }
}
