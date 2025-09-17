<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Cache\Repository;
use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register a fallback cache store if needed
        $this->app->bind('cache.store', function ($app) {
            return new Repository(
                new FileStore(new Filesystem(), storage_path('framework/cache/data'))
            );
        });

        // Early binding for services that other facades might depend on
        $this->app->singleton('transaction.validation', function ($app) {
            return new \App\Services\TransactionValidationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for schema
        Schema::defaultStringLength(191);
        
        // Ensure app is fully initialized before bootstrapping services
        if ($this->app->runningInConsole()) {
            return;
        }

        // Make sure View facade is available
        if (!$this->app->bound('view')) {
            $this->app->singleton('view', function ($app) {
                return $app->make(\Illuminate\View\Factory::class);
            });
        }

        // Make StatusHelper available to all views
        View::share('StatusHelper', \App\Helpers\StatusHelper::class);
        View::share('BadgeHelper', \App\Helpers\BadgeHelper::class);
        View::share('LogHelper', \App\Helpers\LogHelper::class);

        // Prod-only: warn if legacy auth flags accidentally enabled
        try {
            if (app()->environment('production')) {
                if (env('TSMS_ENABLE_LEGACY_JWT', false) || env('TSMS_ENABLE_LEGACY_API', false)) {
                    \Log::warning('Legacy auth flags enabled in production', [
                        'TSMS_ENABLE_LEGACY_JWT' => (bool) env('TSMS_ENABLE_LEGACY_JWT', false),
                        'TSMS_ENABLE_LEGACY_API' => (bool) env('TSMS_ENABLE_LEGACY_API', false),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // no-op
        }
    }
}