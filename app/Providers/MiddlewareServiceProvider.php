<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Router;
use App\Http\Middleware\TransformTextFormat;

class MiddlewareServiceProvider extends ServiceProvider
{
    /**
     * Register middleware with the application.
     */
    public function boot(): void
    {
        // Use dependency injection instead of Facade
        $router = $this->app->make(Router::class);
        
        // Register middleware
        $router->aliasMiddleware('transform.text', TransformTextFormat::class);
    }
    
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register services, but do not use Facades here
    }
}