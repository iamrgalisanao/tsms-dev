<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TransactionStatusService
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function updateStatus($transactionId, $status, array $data = [])
    {
        try {
            $transaction = Transaction::findOrFail($transactionId);
            $transaction->update(array_merge([
                'status' => $status,
                'updated_at' => now()
            ], $data));

            Log::info('Transaction status updated', [
                'id' => $transactionId,
                'status' => $status,
                'data' => $data
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update transaction status', [
                'id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function track($transactionId)
    {
        try {
            $transaction = Transaction::findOrFail($transactionId);
            
            return [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->job_status,
                'attempts' => $transaction->job_attempts,
                'last_attempt' => $transaction->updated_at,
                'completed_at' => $transaction->completed_at,
                'error' => $transaction->last_error,
                'progress' => $this->calculateProgress($transaction)
            ];
        } catch (\Exception $e) {
            Log::error('Transaction status tracking failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getDetailedStatus($transactionId)
    {
        $status = $this->track($transactionId);
        if (!$status) return null;

        return array_merge($status, [
            'processing_time' => $this->calculateProcessingTime($status),
            'retry_count' => $this->getRetryCount($transactionId),
            'is_retriable' => $this->isRetriable($status)
        ]);
    }

    private function isRetriable($status)
    {
        return $status['status'] === self::STATUS_FAILED 
            && $status['attempts'] < 3;
    }

    protected function calculateProgress($transaction)
    {
        switch ($transaction->job_status) {
            case self::STATUS_PENDING:
                return 0;
            case self::STATUS_PROCESSING:
                return 50;
            case self::STATUS_COMPLETED:
                return 100;
            case self::STATUS_FAILED:
                return $transaction->job_attempts >= 3 ? 100 : 75;
            default:
                return 0;
        }
    }

    private function calculateProcessingTime($status)
    {
        if (!$status['last_attempt']) {
            return 0;
        }

        $start = $status['last_attempt'];
        $end = $status['completed_at'] ?? now();

        return $end->diffInSeconds($start);
    }

    private function getRetryCount($transactionId)
    {
        return Cache::remember("transaction_retry_count_{$transactionId}", 60, function() use ($transactionId) {
            return Transaction::where('transaction_id', $transactionId)
                ->value('job_attempts') ?? 0;
        });
    }
}