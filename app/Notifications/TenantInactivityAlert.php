<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInactivityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Use default queue connection and the configured notifications queue name.
     */
    public $connection = null; // use default
    public $queue = null; // mapped via viaQueues()

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;

        if (method_exists($this, 'afterCommit')) {
            $this->afterCommit();
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * For tenant inactivity we only use the database channel so that
     * alerts appear in the TSMS dashboards (Admin/Finance) and no
     * emails are sent.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Route this notification's channels to a consistent queue for observability.
     */
    public function viaQueues(): array
    {
        $queue = config('notifications.notification_queue', 'notifications');

        return [
            'database' => $queue,
        ];
    }

    /**
     * Backoff strategy for queued delivery retries (seconds).
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Get the mail representation of the notification.
     */
    // No mail channel is used for this notification (dashboard-only).

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'tenant_inactivity_alert',
            'title' => 'Tenant Inactivity Detected',
            'message' => "No transactions received in the last " . ($this->data['inactive_minutes'] ?? 60) . " minutes.",
            'tenant_id' => $this->data['tenant_id'] ?? null,
            'tenant_name' => $this->data['tenant_name'] ?? null,
            'inactive_minutes' => $this->data['inactive_minutes'] ?? 60,
            'last_transaction_at' => $this->data['last_transaction_at'] ?? null,
            'active_terminal_count' => $this->data['active_terminal_count'] ?? null,
            'severity' => 'medium',
            'created_at' => now(),
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
