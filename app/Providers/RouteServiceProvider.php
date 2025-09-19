<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

    // Register Sanctum abilities middleware aliases using Route facade
    Route::aliasMiddleware('abilities', \Laravel\Sanctum\Http\Middleware\CheckAbilities::class);
    Route::aliasMiddleware('ability', \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class);
        // Register route middleware aliases here (Laravel 11 style also supported via providers)
        \Illuminate\Support\Facades\Route::aliasMiddleware('capture.terminal.ip', \App\Http\Middleware\CaptureTerminalIp::class);
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('transaction-api', function (Request $request) {
            return Limit::perMinute(60)->by($request->terminal_id ?? $request->ip());
        });
    }

    /**
     * Configure the middleware for the application.
     *
     * @return void
     */
    public function configureMiddleware(): void
    {
        Route::aliasMiddleware('validate.transaction', \App\Http\Middleware\ValidateTransaction::class);
    }
}