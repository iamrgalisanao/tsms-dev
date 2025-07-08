<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class BatchProcessingFailure extends Notification implements ShouldQueue
{
    use Queueable;

    private array $batchData;
    private array $failedTransactions;

    public function __construct(array $batchData, array $failedTransactions = [])
    {
        $this->batchData = $batchData;
        $this->failedTransactions = $failedTransactions;
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
        $batchId = $this->batchData['batch_id'];
        $totalTransactions = $this->batchData['total_transactions'];
        $failedCount = count($this->failedTransactions);
        $successCount = $totalTransactions - $failedCount;

        return (new MailMessage)
                    ->subject('TSMS Alert: Batch Processing Partial Failure')
                    ->priority('high')
                    ->line("Batch processing completed with failures.")
                    ->line("Batch ID: {$batchId}")
                    ->line("Total Transactions: {$totalTransactions}")
                    ->line("Successful: {$successCount}")
                    ->line("Failed: {$failedCount}")
                    ->when($failedCount > 0, function ($message) {
                        $message->line('Failed transactions:');
                        foreach (array_slice($this->failedTransactions, 0, 10) as $failure) {
                            $message->line("- Transaction {$failure['transaction_id']}: {$failure['error_message']}");
                        }
                        if (count($this->failedTransactions) > 10) {
                            $message->line("... and " . (count($this->failedTransactions) - 10) . " more failures");
                        }
                    })
                    ->action('View Batch Details', url("/batches/{$batchId}"))
                    ->line('Please review the failed transactions and retry if necessary.');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'batch_processing_failure',
            'title' => 'Batch Processing Partial Failure',
            'message' => "Batch {$this->batchData['batch_id']} completed with " . count($this->failedTransactions) . " failures",
            'batch_data' => $this->batchData,
            'failed_transactions' => $this->failedTransactions,
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
