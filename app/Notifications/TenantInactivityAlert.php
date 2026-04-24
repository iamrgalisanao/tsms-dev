<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TenantInactivityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Use default queue connection and the configured notifications queue name.
     */
    public $connection = null; // use default
    public $queue = null; // mapped via viaQueues()

    /**
     * Data containing an array of inactive tenants
     * Each element: ['name', 'customer_code', 'inactive_minutes', 'last_transaction_at', 'active_terminal_count']
     */
    private array $inactiveTenants;

    public function __construct(array $inactiveTenants)
    {
        $this->inactiveTenants = $inactiveTenants;

        if (method_exists($this, 'afterCommit')) {
            $this->afterCommit();
        }
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // If the notifiable is a route or has an email, include mail
        if (config('mail.default') !== 'array') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Route this notification's channels to a consistent queue for observability.
     */
    public function viaQueues(): array
    {
        $queue = config('notifications.notification_queue', 'notifications');

        return [
            'mail' => $queue,
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
    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->inactiveTenants);
        $subject = $count > 1 
            ? "TSMS Alert: Consolidated Tenant Inactivity Report ({$count} Tenants)"
            : "TSMS Alert: Tenant Inactivity Detected - " . $this->inactiveTenants[0]['name'];

        $message = (new MailMessage)
            ->subject($subject)
            ->priority(1) // High priority
            ->greeting('Consolidated Tenant Inactivity Alert')
            ->line('The following tenants have not sent any transactions within the configured monitoring window.')
            ->line(''); // Spacer

        // Add tenants as lines/table-like structure
        // Note: Standard MailMessage doesn't have native tables easily, but we can format with lines.
        // For a better experience, we'll use a clean list format.
        foreach ($this->inactiveTenants as $tenant) {
            $message->line("**{$tenant['name']}**")
                ->line("- Customer Code: `{$tenant['customer_code']}`")
                ->line("- Inactivity: {$tenant['inactive_minutes']} minutes")
                ->line("- Last Transaction: " . ($tenant['last_transaction_at'] ?: 'N/A'))
                ->line("- Active Terminals: {$tenant['active_terminal_count']}")
                ->line(''); // Spacer
        }

        return $message
            ->action('View Inactive Tenants Dashboard', url('/admin/tenants'))
            ->line('Please verify the terminal connectivity or network status for these tenants.')
            ->line('This is an automated consolidated alert from the Terminal Sales Monitoring System (TSMS).');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $count = count($this->inactiveTenants);
        
        return [
            'type' => 'tenant_inactivity_alert',
            'title' => $count > 1 ? "{$count} Tenants Inactive" : "Tenant Inactive: " . $this->inactiveTenants[0]['name'],
            'message' => $count > 1 
                ? "Multiple tenants have been inactive for over " . $this->inactiveTenants[0]['inactive_minutes'] . " minutes."
                : "Tenant {$this->inactiveTenants[0]['name']} has been inactive for " . $this->inactiveTenants[0]['inactive_minutes'] . " minutes.",
            'tenants' => $this->inactiveTenants,
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
