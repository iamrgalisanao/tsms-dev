<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantInactivityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public array $inactiveTenants = [];

    public array $inactiveTerminals = [];

    public function __construct(array $inactiveTenants, array $inactiveTerminals = [])
    {
        $this->inactiveTenants = $inactiveTenants;
        $this->inactiveTerminals = $inactiveTerminals;
    }

    public function via($notifiable): array
    {
        $channels = ['database'];

        if (config('mail.default') !== 'array') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function viaQueues(): array
    {
        $queue = config('notifications.notification_queue', 'notifications');

        return [
            'mail' => $queue,
            'database' => $queue,
        ];
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function toMail($notifiable): MailMessage
    {
        $tenantCount = count($this->inactiveTenants);
        $terminalCount = count($this->inactiveTerminals);

        $message = (new MailMessage)
            ->subject("TSMS Alert: Inactivity Report ({$tenantCount} Tenants, {$terminalCount} Terminals)")
            ->priority(1)
            ->greeting('Consolidated Activity Inactivity Alert')
            ->line('The following monitored tenants or terminals have not sent transactions within their configured monitoring windows.');

        if ($tenantCount > 0) {
            $message->line('Tenant alerts:');
            foreach ($this->inactiveTenants as $tenant) {
                $message->line('**' . ($tenant['name'] ?? 'N/A') . '**')
                    ->line('- Customer Code: `' . ($tenant['customer_code'] ?? 'N/A') . '`')
                    ->line('- Inactivity threshold: ' . ($tenant['inactive_minutes'] ?? 'N/A') . ' minutes')
                    ->line('- Last Transaction: ' . (($tenant['last_transaction_at'] ?? null) ?: 'N/A'))
                    ->line('- Active Terminals: ' . ($tenant['active_terminal_count'] ?? 0));
            }
        }

        if ($terminalCount > 0) {
            $message->line('Terminal alerts:');
            foreach ($this->inactiveTerminals as $terminal) {
                $message->line('**' . ($terminal['tenant_name'] ?? 'N/A') . ' / ' . ($terminal['serial_number'] ?? 'Terminal ' . ($terminal['terminal_id'] ?? 'N/A')) . '**')
                    ->line('- Customer Code: `' . ($terminal['customer_code'] ?? 'N/A') . '`')
                    ->line('- Machine Number: ' . (($terminal['machine_number'] ?? null) ?: 'N/A'))
                    ->line('- Inactivity threshold: ' . ($terminal['inactive_minutes'] ?? 'N/A') . ' minutes')
                    ->line('- Last Transaction: ' . (($terminal['last_transaction_at'] ?? null) ?: 'N/A'));
            }
        }

        return $message
            ->action('View Provider Activity Dashboard', url('/monitoring/activity'))
            ->line('Please verify the terminal connectivity or network status for these tenants.')
            ->line('This is an automated consolidated alert from the Terminal Sales Monitoring System (TSMS).');
    }

    public function toDatabase($notifiable): array
    {
        $tenantCount = count($this->inactiveTenants);
        $terminalCount = count($this->inactiveTerminals);

        return [
            'type' => 'tenant_inactivity_alert',
            'title' => "Activity inactivity: {$tenantCount} tenants, {$terminalCount} terminals",
            'message' => "Monitored activity alerts detected for {$tenantCount} tenants and {$terminalCount} terminals.",
            'tenants' => $this->inactiveTenants,
            'terminals' => $this->inactiveTerminals,
            'tenant_count' => $tenantCount,
            'terminal_count' => $terminalCount,
            'severity' => 'medium',
            'created_at' => now(),
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
