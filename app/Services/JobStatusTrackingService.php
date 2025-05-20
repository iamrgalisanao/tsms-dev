<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class JobStatusTrackingService
{
    public function updateStatus(Transaction $transaction, string $status, ?string $error = null)
    {
        try {
            $transaction->update([
                'job_status' => $status,
                'last_error' => $error,
                'completed_at' => in_array($status, ['COMPLETED', 'FAILED']) ? now() : null
            ]);

            Log::info('Job status updated', [
                'transaction_id' => $transaction->id,
                'status' => $status,
                'error' => $error
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update job status', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->id
            ]);
        }
    }
}