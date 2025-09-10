<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionProcessingService
{
    public function processTransaction($data)
    {
        DB::beginTransaction();
        try {
            Log::info('Processing transaction', ['data' => $data]);

            $transaction = Transaction::create([
                'transaction_id' => $data['transaction_id'],
                'customer_code' => $data['customer_code'],
                'terminal_id' => $data['terminal_id'],
                'trade_name' => $data['trade_name'] ?? null,
                'hardware_id' => $data['hardware_id'],
                'machine_number' => $data['machine_number'] ?? null,
                'transaction_timestamp' => $data['transaction_timestamp'],
                'gross_sales' => $data['gross_sales'] ?? $data['base_amount'] ?? 0,
                'payload_checksum' => $data['payload_checksum'],
                'validation_status' => 'PENDING',
                'job_status' => 'QUEUED',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Optionally: handle adjustments, taxes, jobs, validations here if present in $data
            // ...

            if (!$transaction) {
                throw new \Exception('Failed to create transaction record');
            }

            DB::commit();
            Log::info('Transaction created successfully', ['id' => $transaction->id]);

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction processing error', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }
}