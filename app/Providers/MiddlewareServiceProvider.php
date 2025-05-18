<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use App\Http\Middleware\TransformTextFormat;

class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the TransactionValidationService if needed
        $this->app->singleton(\App\Services\TransactionValidationService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Laravel 11 uses route aliasing instead of kernel registration
        $router = $this->app->make(Router::class);
        
        // Register the route middleware alias
        $router->aliasMiddleware('transform.text', TransformTextFormat::class);
        
        // Add middleware to the API group in a Laravel 11 compatible way
        // (instead of using global middleware which is discouraged)
        $router->middlewareGroup('api', [
            // Keep existing API middleware
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            
            // Add the text transformer middleware
            TransformTextFormat::class,
        ]);
    }
}