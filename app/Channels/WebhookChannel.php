<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Custom webhook notification channel for sending notifications to external endpoints
 * Specifically designed for POS terminal callback notifications
 */
class WebhookChannel
{
    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification)
    {
        // Check if the notification has a toWebhook method
        if (!method_exists($notification, 'toWebhook')) {
            Log::warning('Notification does not support webhook channel', [
                'notification_type' => get_class($notification),
            ]);
            return null;
        }

        $webhookData = $notification->toWebhook($notifiable);
        
        Log::info('Webhook notification processed', [
            'notification_type' => get_class($notification),
            'notifiable_type' => get_class($notifiable),
            'webhook_status' => $webhookData['status'] ?? 'unknown',
        ]);

        return $webhookData;
    }
}
