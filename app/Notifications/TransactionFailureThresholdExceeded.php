<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TransactionFailureThresholdExceeded extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Use default queue connection and the configured notifications queue name.
     */
    public $connection = null; // use default
    public $queue = null; // we'll map queues via viaQueues()


    /**
     * Route mail notifications to the ADMIN_EMAIL address in .env
     */
    public function routeNotificationForMail($notifiable)
    {
        return env('ADMIN_EMAIL');
    }

    /** ADR-004: business-INVALID sales (the original alert). */
    public const TYPE_BUSINESS_INVALID = 'business_invalid';

    /** ADR-004: processing failed with no business outcome (PENDING or legacy ''). */
    public const TYPE_PROCESSING_FAILURE = 'processing_failure';

    private array $thresholdData;
    private array $recentFailures;

    public function alertType(): string
    {
        return $this->thresholdData['alert_type'] ?? self::TYPE_BUSINESS_INVALID;
    }

    private function isProcessingFailure(): bool
    {
        return $this->alertType() === self::TYPE_PROCESSING_FAILURE;
    }

    public function __construct(array $thresholdData, array $recentFailures = [])
    {
        $this->thresholdData = $thresholdData;
        $this->recentFailures = $recentFailures;
        // ensure dispatch after DB commit
        if (method_exists($this, 'afterCommit')) {
            $this->afterCommit();
        }
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
        $threshold = $this->thresholdData['threshold'];
        $currentCount = $this->thresholdData['current_count'];
        $timeWindow = $this->thresholdData['time_window_minutes'];
        $posTerminalId = $this->thresholdData['pos_terminal_id'] ?? 'Multiple Terminals';

        return (new MailMessage)
                    ->subject($this->isProcessingFailure()
                        ? 'TSMS Alert: Transaction Processing Failure Threshold Exceeded'
                        : 'TSMS Alert: Transaction Failure Threshold Exceeded')
                    ->priority(1) // High priority (1 = highest, 5 = lowest)
                    ->line($this->isProcessingFailure()
                        ? 'Transaction processing failure threshold has been exceeded (processing failed before validation; TSMS retries these).'
                        : 'Transaction failure threshold has been exceeded.')
                    ->line("POS Terminal: {$posTerminalId}")
                    ->line("Threshold: {$threshold} failures in {$timeWindow} minutes")
                    ->line("Current Count: {$currentCount} failures")
                    ->line("Time Window: " . Carbon::now()->subMinutes($timeWindow)->format('Y-m-d H:i:s') . " to " . Carbon::now()->format('Y-m-d H:i:s'))
                    ->when(count($this->recentFailures) > 0, function ($message) {
                        $message->line('Recent failures:');
                        foreach (array_slice($this->recentFailures, 0, 5) as $failure) {
                            $message->line("- Transaction {$failure['transaction_id']}: {$failure['error_message']} at {$failure['failed_at']}");
                        }
                    })
                    ->action('View Transaction Dashboard', url('/transactions'))
                    ->line('Please investigate the cause of these failures immediately.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        if ($this->isProcessingFailure()) {
            return [
                'type' => 'transaction_processing_failure_threshold_exceeded',
                'alert_type' => self::TYPE_PROCESSING_FAILURE,
                'title' => 'Transaction Processing Failure Threshold Exceeded',
                'message' => "Transaction processing failures exceeded threshold of {$this->thresholdData['threshold']} in {$this->thresholdData['time_window_minutes']} minutes",
                'threshold_data' => $this->thresholdData,
                'recent_failures' => $this->recentFailures,
                'severity' => 'high',
                'pos_terminal_id' => $this->thresholdData['pos_terminal_id'] ?? null,
                'created_at' => now(),
            ];
        }

        return [
            'type' => 'transaction_failure_threshold_exceeded',
            'alert_type' => self::TYPE_BUSINESS_INVALID,
            'title' => 'Transaction Failure Threshold Exceeded',
            'message' => "Transaction failures exceeded threshold of {$this->thresholdData['threshold']} in {$this->thresholdData['time_window_minutes']} minutes",
            'threshold_data' => $this->thresholdData,
            'recent_failures' => $this->recentFailures,
            'severity' => 'high',
            'pos_terminal_id' => $this->thresholdData['pos_terminal_id'] ?? null,
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
