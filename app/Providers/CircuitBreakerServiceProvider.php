<?php

namespace App\Providers;

use App\Services\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class CircuitBreakerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(CircuitBreaker::class, function ($app, $parameters = []) {
            // Default to 'default' if no service key is provided
            $serviceKey = $parameters['serviceKey'] ?? 'default';
            
            return new CircuitBreaker($serviceKey);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Make sure the cache is properly configured
        if (!config('cache.stores.file')) {
            config([
                'cache.stores.file' => [
                    'driver' => 'file',
                    'path' => storage_path('framework/cache/data'),
                ]
            ]);
        }
    }
}
