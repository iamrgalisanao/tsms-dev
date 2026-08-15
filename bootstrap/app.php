<?php

if (! defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}

if (! defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}

if (! defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
            'transform.text.format' => \App\Http\Middleware\TransformTextFormat::class,
            'rate.limit' => \App\Http\Middleware\RateLimitMiddleware::class,
            'api.limit' => \App\Http\Middleware\ApiRateLimiter::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'capture.terminal.ip' => \App\Http\Middleware\CaptureTerminalIp::class,
            'circuit.breaker' => \App\Http\Middleware\CircuitBreakerMiddleware::class,
            'ingestion.payload_size' => \App\Http\Middleware\IngestionPayloadSizeMiddleware::class,
            'ingestion.backpressure' => \App\Http\Middleware\IngestionBackpressureMiddleware::class,
            'ingestion.fairness' => \App\Http\Middleware\IngestionFairnessMiddleware::class,
            'ensure.webapp.token' => \App\Http\Middleware\EnsureWebappToken::class,
            'license.valid' => \App\Http\Middleware\LicenseMiddleware::class,
            'license.vendor' => \App\Http\Middleware\EnsureVendorLicenseAuthority::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Your custom error reporting logic
        $exceptions->reportable(function (\Throwable $e) {
            // Custom reporting logic
        });

        $exceptions->renderable(function (\Throwable $e) {
            // Custom rendering logic
        });
    })
    ->create();
