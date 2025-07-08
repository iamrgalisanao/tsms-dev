<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TransactionFailureThresholdExceeded extends Notification
{
    use Queueable;

    private array $thresholdData;
    private array $recentFailures;

    public function __construct(array $thresholdData, array $recentFailures = [])
    {
        $this->thresholdData = $thresholdData;
        $this->recentFailures = $recentFailures;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
                    ->subject('TSMS Alert: Transaction Failure Threshold Exceeded')
                    ->priority(1) // High priority (1 = highest, 5 = lowest)
                    ->line("Transaction failure threshold has been exceeded.")
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
        return [
            'type' => 'transaction_failure_threshold_exceeded',
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
