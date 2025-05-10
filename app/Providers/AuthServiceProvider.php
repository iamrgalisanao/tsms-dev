<?php

namespace App\Providers;

use App\Http\Middleware\EnsureDashboardAuth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('ensure.dashboard.auth', EnsureDashboardAuth::class);
    }
}
