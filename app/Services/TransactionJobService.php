<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class TransactionJobService
{
    public function updateJobStatus(Transaction $transaction, string $status, ?string $error = null)
    {
        try {
            $transaction->update([
                'job_status' => $status,
                'last_error' => $error,
                'job_attempts' => $transaction->job_attempts + 1,
                'completed_at' => in_array($status, [
                    Transaction::JOB_STATUS_COMPLETED,
                    Transaction::JOB_STATUS_FAILED
                ]) ? now() : null
            ]);

            Log::info('Job status updated', [
                'transaction_id' => $transaction->transaction_id,
                'status' => $status
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update job status', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->transaction_id
            ]);
        }
    }
}