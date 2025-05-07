<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        RateLimiter::enableSeconds();
    }

    public function boot(): void
    {
        // Configure rate limiters with Laravel 11 best practices
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        });
    }

    protected function configureRateLimiting(): void
    {
        // Authentication rate limiting
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinutes(
                config('rate-limiting.default_limits.auth.decay_minutes', 15),
                config('rate-limiting.default_limits.auth.attempts', 5)
            )->by($request->ip())
             ->response(function () {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'retry_after_seconds' => RateLimiter::availableIn('auth:' . request()->ip())
                ], 429);
             });
        });

        // API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(
                config('rate-limiting.default_limits.api.attempts', 60)
            )->by($request->user()?->id ?: $request->ip())
             ->response(function () {
                return response()->json([
                    'message' => 'Too many requests.',
                    'retry_after_seconds' => RateLimiter::availableIn('api:' . (request()->user()?->id ?: request()->ip()))
                ], 429);
             });
        });

        // Circuit breaker rate limiting
        RateLimiter::for('circuit-breaker', function (Request $request) {
            $key = sprintf('circuit-breaker:%s:%s',
                $request->user()?->id ?: 'guest',
                $request->header('X-Tenant-ID', 'default')
            );
            
            return Limit::perMinute(
                config('rate-limiting.default_limits.circuit_breaker.attempts', 30)
            )->by($key)
             ->response(function () use ($key) {
                return response()->json([
                    'message' => 'Circuit breaker rate limit exceeded.',
                    'retry_after_seconds' => RateLimiter::availableIn($key)
                ], 429);
             });
        });
    }
}
