<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Notification to send transaction validation results back to POS terminals
 * This implements callback notifications to inform terminals about transaction status
 */
class TransactionResultNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public array $transactionData;
    public string $validationResult;
    public array $validationErrors;
    public string $terminalCallbackUrl;

    public function __construct(
        array $transactionData,
        string $validationResult,
        array $validationErrors = [],
        string $terminalCallbackUrl = null
    ) {
        $this->transactionData = $transactionData;
        $this->validationResult = $validationResult; // 'VALID', 'INVALID', 'PENDING'
        $this->validationErrors = $validationErrors;
        $this->terminalCallbackUrl = $terminalCallbackUrl;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database']; // Always log to database
        
        if ($this->terminalCallbackUrl) {
            $channels[] = 'webhook'; // Custom webhook channel
        }
        
        return $channels;
    }

    /**
     * Send webhook notification to POS terminal
     */
    public function toWebhook(object $notifiable): array
    {
        $payload = [
            'transaction_id' => $this->transactionData['transaction_id'],
            'submission_uuid' => $this->transactionData['submission_uuid'] ?? null,
            'validation_result' => $this->validationResult,
            'validation_status' => $this->validationResult === 'VALID' ? 'success' : 'failed',
            'processing_timestamp' => now()->toISOString(),
            'errors' => $this->validationErrors,
            'terminal_id' => $this->transactionData['terminal_id'] ?? null,
            'customer_code' => $this->transactionData['customer_code'] ?? null,
        ];

        try {
            // Send HTTP POST to terminal callback URL
            $response = Http::timeout(30)
                ->retry(3, 1000) // Retry 3 times with 1 second delay
                ->post($this->terminalCallbackUrl, $payload);

            Log::info('Transaction result notification sent to terminal', [
                'terminal_callback_url' => $this->terminalCallbackUrl,
                'transaction_id' => $this->transactionData['transaction_id'],
                'validation_result' => $this->validationResult,
                'response_status' => $response->status(),
            ]);

            return [
                'status' => 'sent',
                'response_code' => $response->status(),
                'sent_at' => now()->toISOString(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to send transaction result notification to terminal', [
                'terminal_callback_url' => $this->terminalCallbackUrl,
                'transaction_id' => $this->transactionData['transaction_id'],
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
            ];
        }
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'transaction_result',
            'title' => 'Transaction Validation Result',
            'message' => "Transaction {$this->transactionData['transaction_id']} validation result: {$this->validationResult}",
            'transaction_data' => $this->transactionData,
            'validation_result' => $this->validationResult,
            'validation_errors' => $this->validationErrors,
            'terminal_callback_url' => $this->terminalCallbackUrl,
            'severity' => $this->validationResult === 'VALID' ? 'info' : 'warning',
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
