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
                'terminal_id' => $data['terminal_id'],
                'gross_sales' => $data['gross_sales'],
                'validation_status' => 'PENDING',
                'job_status' => 'QUEUED',
                'created_at' => now(),
                'updated_at' => now()
            ]);

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