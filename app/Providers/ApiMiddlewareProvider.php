<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\TestAuthBypass;

class ApiMiddlewareProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middlewareGroup('api', [
            'json',
            TestAuthBypass::class,
            // Add other API middleware here
        ]);

        // Register test-specific middleware
        if ($this->app->environment('testing')) {
            Route::middlewareGroup('test', [
                TestAuthBypass::class
            ]);
        }
    }
}
