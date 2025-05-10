<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Security\Contracts\SecurityMonitorInterface;
use App\Services\Security\Contracts\LoginSecurityMonitorInterface;
use App\Services\Security\Contracts\SecurityAlertHandlerInterface;
use App\Services\Security\SecurityMonitorService;
use App\Services\Security\LoginSecurityMonitorService;
use App\Services\Security\SecurityAlertHandlerService;

class SecurityMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind SecurityAlertHandler first as it's a dependency
        $this->app->singleton(SecurityAlertHandlerInterface::class, SecurityAlertHandlerService::class);

        // Bind SecurityMonitor with its dependencies
        $this->app->singleton(SecurityMonitorInterface::class, SecurityMonitorService::class);

        // Bind LoginSecurityMonitor with configured parameters
        $this->app->singleton(LoginSecurityMonitorInterface::class, function ($app) {
            return new LoginSecurityMonitorService(
                $app->make(SecurityMonitorInterface::class),
                config('security.max_login_attempts', 5),
                config('security.login_decay_minutes', 15)
            );
        });
    }

    public function boot(): void
    {
        // Register custom security log channel
        $this->app['log']->channel('security');

        // Merge security configuration
        $this->mergeConfigFrom(
            __DIR__.'/../../config/security.php', 'security'
        );        // Register security event listeners
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/security.php' => config_path('security.php'),
            ], 'security-config');
        }

        // Register security middleware
        $router = $this->app['router'];
        
        // Register middleware aliases
        $router->aliasMiddleware('security.monitor', \App\Http\Middleware\SecurityMonitorMiddleware::class);
        $router->aliasMiddleware('security.login', \App\Http\Middleware\LoginSecurityMiddleware::class);
    }
}