<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\TransactionValidationService;

class ProcessTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    public function handle(TransactionValidationService $validator)
    {
        try {
            // Update status to processing
            $this->transaction->update([
                'job_status' => Transaction::JOB_STATUS_PROCESSING,
                'job_attempts' => $this->transaction->job_attempts + 1
            ]);

            // Run comprehensive validation
            $validation = $validator->validate($this->transaction);

            // Update final status
            $this->transaction->update([
                'validation_status' => $validation['is_valid'] ? 'VALID' : 'INVALID',
                'validation_details' => $validation['details'],
                'job_status' => Transaction::JOB_STATUS_COMPLETED,
                'completed_at' => now()
            ]);
        } catch (\Exception $e) {
            Log::error('Transaction processing failed', [
                'transaction_id' => $this->transaction->id,
                'error' => $e->getMessage(),
                'validation_details' => $this->transaction->validation_details ?? null
            ]);

            $this->transaction->update([
                'job_status' => Transaction::JOB_STATUS_FAILED,
                'last_error' => $e->getMessage()
            ]);
        }
    }
}