<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\RateLimiter\RateLimiterService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Route;

class RateLimitingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RateLimiterService::class, function ($app) {
            return new RateLimiterService($app->make(RateLimiter::class));
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
