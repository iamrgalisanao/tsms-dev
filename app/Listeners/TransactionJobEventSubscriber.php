<?php

namespace App\Listeners;

use App\Models\Transaction;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

class TransactionJobEventSubscriber
{
    public function handleJobProcessing($event)
    {
        if ($this->isTransactionJob($event)) {
            $transactionId = $this->getTransactionId($event);
            Transaction::where('id', $transactionId)->update([
                'status' => 'processing',
                'attempts' => $event->job->attempts()
            ]);
        }
    }

    public function handleJobProcessed($event)
    {
        if ($this->isTransactionJob($event)) {
            $transactionId = $this->getTransactionId($event);
            Transaction::where('id', $transactionId)->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        }
    }

    public function handleJobFailed($event)
    {
        if ($this->isTransactionJob($event)) {
            $transactionId = $this->getTransactionId($event);
            Transaction::where('id', $transactionId)->update([
                'status' => 'failed',
                'error' => $event->exception->getMessage(),
                'failed_at' => now()
            ]);
        }
    }

    private function isTransactionJob($event)
    {
        return $event->job && $event->job->resolveName() === 'App\Jobs\ProcessTransactionJob';
    }

    private function getTransactionId($event)
    {
        $payload = json_decode($event->job->getRawBody(), true);
        return $payload['command_data']['transaction']['id'] ?? null;
    }

    public function subscribe($events)
    {
        $events->listen(
            JobProcessing::class,
            [self::class, 'handleJobProcessing']
        );

        $events->listen(
            JobProcessed::class,
            [self::class, 'handleJobProcessed']
        );

        $events->listen(
            JobFailed::class,
            [self::class, 'handleJobFailed']
        );
    }
}