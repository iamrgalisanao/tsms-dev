<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('transactions');
    }

    public function broadcastWith()
    {
        return [
            'id' => $this->transaction->id,
            'transaction_id' => $this->transaction->transaction_id,
            'validation_status' => $this->transaction->validation_status,
            'job_status' => $this->transaction->job_status,
            'job_attempts' => $this->transaction->job_attempts,
            'updated_at' => $this->transaction->updated_at->toIso8601String()
        ];
    }
}