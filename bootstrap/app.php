<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Define middleware groups
        $middleware->group('api', [
            // Laravel's built-in middleware
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'transform.text.format' => \App\Http\Middleware\TransformTextFormat::class
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Your custom error reporting logic
        $exceptions->reportable(function (\Throwable $e) {
            // Custom reporting logic
        });
        
        $exceptions->renderable(function (\Throwable $e) {
            // Custom rendering logic 
        });
    })->create();