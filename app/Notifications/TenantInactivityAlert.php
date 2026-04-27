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
     * Data containing an array of inactive tenants
     * Each element: ['name', 'customer_code', 'inactive_minutes', 'last_transaction_at', 'active_terminal_count']
     */
    public array $inactiveTenants;

    /**
     * Create a new notification instance.
     */
    public function __construct(array $inactiveTenants)
    {
        $this->inactiveTenants = $inactiveTenants;
        
        // Ensure this is only processed after DB commit
        if (property_exists($this, 'afterCommit')) {
            $this->afterCommit = true;
        }
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = ['database'];

        // Default to mail if specifically not disabled by 'array' driver during tests
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
    public function toMail($notifiable): MailMessage
    {
        $count = count($this->inactiveTenants);
        $subject = $count > 1 
            ? "TSMS Alert: Consolidated Tenant Inactivity Report ({$count} Tenants)"
            : "TSMS Alert: Tenant Inactivity Detected - " . ($this->inactiveTenants[0]['name'] ?? 'Unknown');

        $message = (new MailMessage)
            ->subject($subject)
            ->priority(1) // High priority
            ->greeting('Consolidated Tenant Inactivity Alert')
            ->line('The following tenants have not sent any transactions within the configured monitoring window.')
            ->line('');

        foreach ($this->inactiveTenants as $tenant) {
            $message->line("**" . ($tenant['name'] ?? 'N/A') . "**")
                ->line("- Customer Code: `{$tenant['customer_code']}`")
                ->line("- Inactivity: {$tenant['inactive_minutes']} minutes")
                ->line("- Last Transaction: " . ($tenant['last_transaction_at'] ?: 'N/A'))
                ->line("- Active Terminals: {$tenant['active_terminal_count']}")
                ->line('');
        }

        return $message
            ->action('View Inactive Tenants Dashboard', url('/admin/tenants'))
            ->line('Please verify the terminal connectivity or network status for these tenants.')
            ->line('This is an automated consolidated alert from the Terminal Sales Monitoring System (TSMS).');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase($notifiable): array
    {
        $count = count($this->inactiveTenants);
        
        return [
            'type' => 'tenant_inactivity_alert',
            'title' => $count > 1 ? "{$count} Tenants Inactive" : "Tenant Inactive: " . ($this->inactiveTenants[0]['name'] ?? 'N/A'),
            'message' => $count > 1 
                ? "Multiple tenants have been inactive for over " . ($this->inactiveTenants[0]['inactive_minutes'] ?? 'N/A') . " minutes."
                : "Tenant " . ($this->inactiveTenants[0]['name'] ?? 'N/A') . " has been inactive for " . ($this->inactiveTenants[0]['inactive_minutes'] ?? 'N/A') . " minutes.",
            'tenants' => $this->inactiveTenants,
            'severity' => 'medium',
            'created_at' => now(),
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}

