<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\RateLimitingMiddleware;
use App\Http\Middleware\TransformTextFormat;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;

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

        // Register middleware aliases - this replaces the old Kernel.php approach
        Route::aliasMiddleware('transform.text', TransformTextFormat::class);
        
        // Add the middleware to the global middleware stack if needed
        // $this->app['router']->pushMiddlewareToGroup('api', TransformTextFormat::class);
    }
}