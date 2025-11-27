<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use App\Listeners\LogAuthenticationEvent;
use App\Listeners\LogAuthenticationFailure;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use App\Listeners\LogRbacEvent;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Login::class => [
            LogAuthenticationEvent::class,
        ],
        Logout::class => [
            LogAuthenticationEvent::class,
        ],
        Failed::class => [
            LogAuthenticationFailure::class,
            LogAuthenticationEvent::class,
        ],
        // RBAC attach/detach events from Spatie
        RoleAttached::class => [
            LogRbacEvent::class,
        ],
        RoleDetached::class => [
            LogRbacEvent::class,
        ],
        PermissionAttached::class => [
            LogRbacEvent::class,
        ],
        PermissionDetached::class => [
            LogRbacEvent::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}