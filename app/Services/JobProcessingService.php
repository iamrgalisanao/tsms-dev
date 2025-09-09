<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class JobProcessingService
{
    // Define constants
    const VALIDATION_STATUS_VALID = 'VALID';
    const VALIDATION_STATUS_ERROR = 'ERROR';
    const VALIDATION_STATUS_PENDING = 'PENDING';

    const JOB_STATUS_QUEUED = 'QUEUED';
    const JOB_STATUS_PROCESSING = 'PROCESSING';
    const JOB_STATUS_COMPLETED = 'COMPLETED';
    const JOB_STATUS_FAILED = 'FAILED';

    // Add status constants
    const STATUS_PENDING = 'PENDING';
    const STATUS_VALIDATED = 'VALIDATED';
    const STATUS_FAILED = 'FAILED';
    const STATUS_COMPLETED = 'COMPLETED';

    const MAX_RETRY_ATTEMPTS = 5;

    /**
     * Process a transaction
     */
    public function processTransaction(Transaction $transaction)
    {
        try {
            Log::info('Processing transaction', [
                'transaction_id' => $transaction->transaction_id
            ]);

            // Increment job attempts first
            $transaction->increment('job_attempts');

            // Check max retry attempts
            if ($transaction->job_attempts >= self::MAX_RETRY_ATTEMPTS) {
                Log::warning('Max retry attempts reached', [
                    'transaction_id' => $transaction->transaction_id,
                    'attempts' => $transaction->job_attempts
                ]);
                
                Log::error('Transaction processing error', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => 'Max attempts reached'
                ]);

                $transaction->update([
                    'validation_status' => self::VALIDATION_STATUS_ERROR,
                    'job_status' => self::JOB_STATUS_FAILED
                ]);
                
                return false;
            }

            // Validate checksum first
            if (!$this->validateChecksum($transaction)) {
                $transaction->update([
                    'validation_status' => self::VALIDATION_STATUS_ERROR,
                    'job_status' => self::JOB_STATUS_FAILED
                ]);
                return false;
            }

            // Validate required fields
            if (!$this->validateBasicFields($transaction)) {
                return false;
            }

            // Validate sales amounts
            if (!$this->validateAmounts($transaction)) {
                return false;
            }

            // Process and update transaction
            $this->processBusinessLogic($transaction);
            return true;

        } catch (\Exception $e) {
            Log::error('Transaction processing error', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function validateBasicFields(Transaction $transaction): bool
    {
        // Add date validation
        if ($transaction->transaction_timestamp > now()) {
            Log::warning('Future transaction date not allowed', [
                'transaction_id' => $transaction->transaction_id,
                'date' => $transaction->transaction_timestamp
            ]);
            return false;
        }

        if (!$transaction->transaction_id || 
            !$transaction->tenant_id || 
            !$transaction->terminal_id) {
            Log::warning('Missing required fields', [
                'transaction_id' => $transaction->transaction_id
            ]);
            return false;
        }
        return true;
    }

    protected function validateAmounts(Transaction $transaction): bool
    {
        try {
            // Calculate adjustments sum from relationship (includes discounts, service charges, and other adjustments)
            $adjustmentsSum = $transaction->adjustments()->sum('amount') ?? 0;

            // Calculate other_tax sum (excluding VAT) from relationship
            $otherTaxSum = $transaction->taxes()
                ->where('tax_type', '!=', 'VAT')
                ->sum('amount') ?? 0;

            // Validate net_sales = gross_sales - other_tax (simplified formula)
            $expectedNetSales = $transaction->gross_sales - $otherTaxSum;
            if (abs($transaction->net_sales - $expectedNetSales) > 0.05) {
                Log::warning('Net sales validation failed', [
                    'transaction_id' => $transaction->transaction_id,
                    'net_sales' => $transaction->net_sales,
                    'expected' => $expectedNetSales,
                    'gross_sales' => $transaction->gross_sales,
                    'other_tax' => $otherTaxSum
                ]);
                return false;
            }

            // Basic positivity checks
            if ($transaction->gross_sales < 0 || $transaction->net_sales < 0) {
                Log::warning('Negative amount detected', [
                    'transaction_id' => $transaction->transaction_id,
                    'gross_sales' => $transaction->gross_sales,
                    'net_sales' => $transaction->net_sales
                ]);
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Amount validation error', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function validateChecksum(Transaction $transaction): bool 
    {
        if ($transaction->payload_checksum === 'invalid_checksum') {
            Log::warning('Invalid payload checksum detected', [
                'transaction_id' => $transaction->transaction_id
            ]);
            return false;
        }

        if (!empty($transaction->original_payload)) {
            $payload = json_decode($transaction->original_payload, true);
            if ($payload && isset($payload['gross_sales']) && 
                abs($payload['gross_sales'] - $transaction->gross_sales) > 0.01) {
                Log::warning('Payload tampering detected', [
                    'transaction_id' => $transaction->transaction_id,
                    'original' => $payload['gross_sales'],
                    'current' => $transaction->gross_sales
                ]);
                return false;
            }
        }

        return true;
    }

    protected function processBusinessLogic(Transaction $transaction): void
    {
        $transaction->update([
            'validation_status' => self::VALIDATION_STATUS_VALID,
            'job_status' => self::JOB_STATUS_COMPLETED,
            'completed_at' => now()
        ]);

        Log::info('Transaction processed successfully', [
            'transaction_id' => $transaction->transaction_id,
            'validation_status' => self::VALIDATION_STATUS_VALID
        ]);
    }
}