<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\RateLimitingMiddleware;
use Illuminate\Contracts\Foundation\Application;

class MiddlewareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RateLimitingMiddleware::class);
    }

    public function boot(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        // Register the rate limiting middleware
        $router->aliasMiddleware('rate.limit', RateLimitingMiddleware::class);
        
        // Define middleware groups
        $router->middlewareGroup('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            RateLimitingMiddleware::class . ':api',
        ]);

        $router->middlewareGroup('circuit-breaker', [
            'auth:sanctum',
            RateLimitingMiddleware::class . ':circuit_breaker',
        ]);
    }
}