<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use App\Events\TransactionUpdated;

class TransactionService
{
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
            'base_amount' => $payload['base_amount'],
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
        return $transaction->processingHistory()->create([
            'status' => $status,
            'message' => $message,
            'attempt_number' => $transaction->job_attempts,
            'created_by' => 'system'
        ]);
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