<?php

namespace App\Providers;

use App\Http\Middleware\TransformTextFormat;
use App\Services\TransactionValidationService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class TransactionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register the transaction validation service
        $this->app->singleton(TransactionValidationService::class, function ($app) {
            return new TransactionValidationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the route middleware alias
        Route::aliasMiddleware('transform.text', TransformTextFormat::class);
    }
}