<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ADR-004 D7: per-run summary of transactions the watchdog stopped re-dispatching because
 * they reached the retry cap (still PENDING with a failed job). One alert per watchdog run.
 * Carries references only (pk, transaction_id, tenant, terminal, error class), never payload
 * content or raw error messages.
 */
class TransactionRetryCapReachedAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $totalCount  rows that reached the cap in this run
     * @param  array<int, array{transaction_pk:int, transaction_id:?string, tenant_id:?int, terminal_id:?int, last_error_class:string}>  $rows  at most 20
     */
    public function __construct(
        public int $totalCount,
        public array $rows,
        public int $maxRequeueAttempts,
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
        $mail = (new MailMessage)
            ->subject('TSMS Alert: '.$this->title())
            ->priority(1)
            ->greeting('Transaction Retry Cap Reached')
            ->line($this->message());

        foreach ($this->rows as $row) {
            $mail->line(sprintf(
                '- PK %d, transaction %s, tenant %s, terminal %s, error %s',
                $row['transaction_pk'],
                $row['transaction_id'] ?? 'N/A',
                $row['tenant_id'] ?? 'N/A',
                $row['terminal_id'] ?? 'N/A',
                $row['last_error_class'],
            ));
        }
        if ($this->totalCount > count($this->rows)) {
            $mail->line('... and '.($this->totalCount - count($this->rows)).' more (see the [Watchdog] retry cap reached log entries).');
        }

        return $mail
            ->line('These sales stay PENDING (not validated). Check the queue workers and each transaction last_error.')
            ->line('This is an automated alert from the Terminal Sales Monitoring System (TSMS).');
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'transaction_retry_cap_reached',
            'title' => $this->title(),
            'message' => $this->message(),
            'total_count' => $this->totalCount,
            'max_requeue_attempts' => $this->maxRequeueAttempts,
            'transactions' => $this->rows,
            'severity' => 'high',
            'created_at' => now(),
        ];
    }

    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function title(): string
    {
        return "Transaction retry cap reached: {$this->totalCount} transaction(s)";
    }

    private function message(): string
    {
        return "{$this->totalCount} transaction(s) are still PENDING with a failed job after {$this->maxRequeueAttempts} watchdog re-dispatches.";
    }
}
