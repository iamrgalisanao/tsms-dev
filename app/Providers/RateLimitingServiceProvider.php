<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\RateLimiter\RateLimiterService;
use App\Services\RateLimiter\RateLimitMonitor;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;

class RateLimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind monitor first
        $this->app->singleton(RateLimitMonitor::class, function ($app) {
            return new RateLimitMonitor();
        });

        $this->app->singleton(RateLimiterService::class, function ($app) {
            return new RateLimiterService(
                $app->make(RateLimiter::class),
                $app->make(RateLimitMonitor::class)
            );
        });
    }

    public function boot(): void
    {
        // Register middleware aliases
        Route::aliasMiddleware('api.limit', \App\Http\Middleware\ApiRateLimiter::class);
        
        // Load rate limiting configuration
        $this->mergeConfigFrom(
            __DIR__.'/../../config/rate-limiting.php', 'rate-limiting'
        );
    }
}
