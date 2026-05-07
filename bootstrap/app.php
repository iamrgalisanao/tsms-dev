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
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'circuit.breaker' => \App\Http\Middleware\CircuitBreakerMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle unauthenticated API requests with detailed JSON
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'TSMS_AUTH_001',
                    'message' => 'Authentication failed or token missing.',
                    'resolution' => [
                        'step_1' => 'Ensure the "Accept: application/json" header is present in your request.',
                        'step_2' => 'Provide a valid "Authorization: Bearer <TOKEN>" header.',
                        'step_3' => 'Verify that your "hardware_id" matches the Serial Number of the authenticated terminal.',
                        'docs' => 'https://stagingtsms.pitx.com.ph/docs/POS_V2.1_Integration_Addendum.md'
                    ]
                ], 401);
            }
            return null;
        });

        // Catch 404 and 403 web requests to serve the React SPA shell
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                return null; // Let Laravel handle API errors as JSON
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->view('app', [], 404);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException || 
                $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->view('app', [], 403);
            }

            return null;
        });
    })
    ->withProviders([
        App\Providers\HorizonServiceProvider::class,
    ])
    ->create();