<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Events\TransactionUpdated;

class TransactionService
{
    /**
     * Process a refund for an existing transaction.
     *
     * @param Transaction $transaction
     * @param array $refundData
     * @return Transaction
     */
    public function processRefund(Transaction $transaction, array $refundData): Transaction
    {
        if (!$transaction->canRefund()) {
            throw new \Exception('Transaction cannot be refunded.');
        }

        // Enforce that refund amount does not exceed net sales
        if (isset($refundData['refund_amount']) && (float)$refundData['refund_amount'] > (float)$transaction->net_sales) {
            throw new \Exception('Refund amount cannot exceed the original net sales.');
        }
        $transaction->update([
            'is_refunded' => $refundData['is_refunded'] ?? true,
            'refund_amount' => $refundData['refund_amount'] ?? null,
            'refund_reason' => $refundData['refund_reason'] ?? null,
            'refund_reference' => $transaction->transaction_id,
        ]);
        $this->logTransactionHistory($transaction, 'REFUNDED', $refundData['refund_reason'] ?? null);
        event(new TransactionUpdated($transaction));
        return $transaction;
    }
    public function store(array $payload): Transaction
    {
        // Exclude any system-computed fields from the payload for checksum
        $checksumInput = collect($payload)
            ->except(['payload_checksum'])
            ->sortKeys()
            ->toJson();

        // Compute SHA-256 hash
        $payload['payload_checksum'] = hash('sha256', $checksumInput);

        // Log the computed checksum for debugging
        Log::info('Computed payload_checksum:', ['checksum' => $payload['payload_checksum']]);

        // Only allow new schema fields
        $transaction = Transaction::create([
            'customer_code' => $payload['customer_code'],
            'terminal_id' => $payload['terminal_id'],
            'transaction_id' => $payload['transaction_id'],
            'hardware_id' => $payload['hardware_id'],
            'transaction_timestamp' => $payload['transaction_timestamp'],
            'gross_sales' => $payload['gross_sales'] ?? 0,
            'payload_checksum' => $payload['payload_checksum'],
            'created_at' => $payload['created_at'] ?? now(),
            'updated_at' => $payload['updated_at'] ?? now(),
        ]);

        // Optionally: handle adjustments, taxes, jobs, validations here if present in payload
        // ...

        return $transaction;
    }

    protected function logTransactionHistory($transaction, $status, $message = null)
    {
        // Safe Logging: Check if the table exists to prevent crash if migration is missing
        if (Schema::hasTable('transaction_histories')) {
            return $transaction->processingHistory()->create([
                'status' => $status,
                'message' => $message,
                'attempt_number' => $transaction->job_attempts,
                'created_by' => 'system'
            ]);
        }

        // Fallback to standard Laravel logs
        Log::info("Transaction History [{$status}]: " . ($message ?? 'N/A'), [
            'transaction_id' => $transaction->transaction_id,
            'terminal_id' => $transaction->terminal_id,
        ]);

        return null;
    }

    protected function updateStatus($transaction, $status, $message = null)
    {
        $transaction->update(['validation_status' => $status]);
        $this->logTransactionHistory($transaction, $status, $message);
        event(new TransactionUpdated($transaction));
    }

    protected function updateTransactionStatus($transaction, $status, $jobStatus = null)
    {
        $transaction->validation_status = $status;
        if ($jobStatus) {
            $transaction->job_status = $jobStatus;
        }
        $transaction->save();

        event(new TransactionUpdated($transaction));
    }
}