<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Cache\Repository;
use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;

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
    }
}