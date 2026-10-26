<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * US3 (FR-009..FR-011): intake rejection alert. Carries references only
 * (tenant, terminal, error code, count, window), never payload content.
 */
class IntakeRejectionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE_REJECTION_SPIKE = 'rejection_spike';

    public const TYPE_NO_ACCEPTED_SALES = 'no_accepted_sales';

    public function __construct(
        public string $alertType,
        public int $tenantId,
        public int $terminalId,
        public ?string $errorCode,
        public int $count,
        public int $windowMinutes,
    ) {}

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
        return (new MailMessage)
            ->subject('TSMS Alert: '.$this->title())
            ->priority(1)
            ->greeting('Intake Rejection Alert')
            ->line($this->message())
            ->line('- Tenant ID: '.$this->tenantId)
            ->line('- Terminal ID: '.$this->terminalId)
            ->line('- Error code: '.($this->errorCode ?? 'N/A'))
            ->line('- Rejected submissions: '.$this->count)
            ->line('- Window: '.$this->windowMinutes.' minutes')
            ->action('View Provider Activity Dashboard', url('/monitoring/activity'))
            ->line('Please check the terminal configuration and the rejection codes in transaction_intake.')
            ->line('This is an automated alert from the Terminal Sales Monitoring System (TSMS).');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'intake_rejection_alert',
            'alert_type' => $this->alertType,
            'title' => $this->title(),
            'message' => $this->message(),
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
            'error_code' => $this->errorCode,
            'count' => $this->count,
            'window_minutes' => $this->windowMinutes,
            'severity' => $this->alertType === self::TYPE_NO_ACCEPTED_SALES ? 'high' : 'medium',
            'created_at' => now(),
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function title(): string
    {
        return $this->alertType === self::TYPE_NO_ACCEPTED_SALES
            ? "No accepted sales: terminal {$this->terminalId}"
            : "Rejection spike: terminal {$this->terminalId} ({$this->errorCode})";
    }

    private function message(): string
    {
        return $this->alertType === self::TYPE_NO_ACCEPTED_SALES
            ? "Terminal {$this->terminalId} (tenant {$this->tenantId}) had {$this->count} rejected submissions and no accepted submissions in the last {$this->windowMinutes} minutes."
            : "Terminal {$this->terminalId} (tenant {$this->tenantId}) had {$this->count} submissions rejected with {$this->errorCode} in the last {$this->windowMinutes} minutes.";
    }
}
