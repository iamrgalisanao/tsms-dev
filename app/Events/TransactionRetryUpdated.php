<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionRetryUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    public function __construct($transaction)
    {
        $this->transaction = $transaction;
    }

    public function broadcastOn()
    {
        return new Channel('transaction-updates');
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->transaction->id,
            'transaction_id' => $this->transaction->transaction_id,
            'terminal_uid' => 'TERM-' . $this->transaction->terminal_id,
            'job_attempts' => (int) $this->transaction->job_attempts,
            'job_status' => $this->transaction->job_status,
            'last_error' => $this->transaction->last_error,
            'updated_at' => $this->transaction->updated_at->format('Y-m-d H:i:s')
        ];
    }
}
