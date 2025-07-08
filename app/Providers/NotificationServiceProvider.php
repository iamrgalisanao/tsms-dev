<?php

namespace App\Providers;

use App\Channels\WebhookChannel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Notification;

class NotificationServiceProvider extends ServiceProvider
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
        // Register the webhook notification channel
        Notification::extend('webhook', function ($app) {
            return new WebhookChannel();
        });
    }
}
