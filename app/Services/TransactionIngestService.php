<?php


namespace App\Services;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\DB;
use App\Services\DeadlockRetryService;

final class TransactionIngestService
{
    protected DeadlockRetryService $retryService;

    public function __construct(DeadlockRetryService $retryService)
    {
        $this->retryService = $retryService;
    }

        /**
         * Ingest a transaction payload atomically with insertOrIgnore and deadlock retry.
         *
         * @param array $payload
         * @return array
         */
        public function ingest(array $payload): array
        {
            try {
                return $this->retryService->withDeadlockRetry(function () use ($payload) {
                    return DB::transaction(function () use ($payload) {
                        $parent = $this->normalizePayload($payload);
                        $inserted = DB::table('transactions')->insertOrIgnore($parent);

                        if ($inserted === 1) {
                            $transaction = DB::table('transactions')
                                ->where('transaction_id', $parent['transaction_id'])
                                ->first();
                            if (!$transaction) {
                                // Should not happen, but guard for safety
                                Log::error('TransactionIngestService: Inserted but not found', $parent);
                                return [
                                    'status' => 'failed',
                                    'id' => null,
                                    'transaction_id' => $parent['transaction_id'],
                                    'terminal_id' => $parent['terminal_id'],
                                    'message' => 'inserted but not found',
                                ];
                            }
                            $this->insertAdjustments($transaction->id, $payload['adjustments'] ?? []);
                            $this->insertTaxes($transaction->id, $payload['taxes'] ?? []);
                            return [
                                'status' => 'accepted',
                                'id' => $transaction->id,
                                'transaction_id' => $transaction->transaction_id,
                                'terminal_id' => $transaction->terminal_id,
                                'message' => 'created',
                            ];
                        }

                        $existing = DB::table('transactions')
                            ->where('transaction_id', $parent['transaction_id'])
                            ->first();

                        if (!$existing) {
                            Log::warning('TransactionIngestService: insertOrIgnore returned 0 but no existing transaction found', $parent);
                            return [
                                'status' => 'failed',
                                'id' => null,
                                'transaction_id' => $parent['transaction_id'],
                                'terminal_id' => $parent['terminal_id'],
                                'message' => 'insert ignored but no existing transaction found',
                            ];
                        }

                        return [
                            'status' => 'already_processed',
                            'id' => $existing->id,
                            'transaction_id' => $existing->transaction_id,
                            'terminal_id' => $existing->terminal_id,
                            'message' => 'already exists',
                        ];
                    }, 1);
                });
            } catch (\Throwable $e) {
                Log::error('TransactionIngestService: ingest failed', ['error' => $e->getMessage(), 'payload' => $payload]);
                return [
                    'status' => 'failed',
                    'id' => null,
                    'transaction_id' => $payload['transaction_id'] ?? null,
                    'terminal_id' => $payload['terminal_id'] ?? null,
                    'message' => 'ingest_failed',
                ];
            }
        }

    /**
     * Normalize payload for DB persistence.
     */
    protected function normalizePayload(array $payload): array
    {
        // Enforce all required fields, no defaults for required
        $required = [
            'tenant_id',
            'terminal_id',
            'transaction_id',
            'hardware_id',
            'transaction_timestamp',
            'gross_sales',
            'customer_code',
            'payload_checksum',
        ];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                throw new \InvalidArgumentException("$field is required in transaction payload");
            }
        }
        return [
            'tenant_id' => $payload['tenant_id'],
            'terminal_id' => $payload['terminal_id'],
            'transaction_id' => $payload['transaction_id'],
            'hardware_id' => $payload['hardware_id'],
            'receipt_no' => $payload['receipt_no'] ?? null,
            'transaction_timestamp' => $payload['transaction_timestamp'],
            'gross_sales' => $payload['gross_sales'],
            'customer_code' => $payload['customer_code'],
            'payload_checksum' => $payload['payload_checksum'],
            'validation_status' => $payload['validation_status'] ?? 'PENDING',
            'created_at' => $payload['created_at'] ?? now(),
            'updated_at' => $payload['updated_at'] ?? now(),
        ];
    }

    /**
     * Insert adjustments only for new parent.
     */
    protected function insertAdjustments($transactionPk, array $adjustments): void
    {
        foreach ($adjustments as $adj) {
            // Only insert if both adjustment_type and amount are present
            if (!isset($adj['adjustment_type'], $adj['amount']) || $adj['adjustment_type'] === '' || $adj['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed adjustment row', ['transaction_pk' => $transactionPk, 'row' => $adj]);
                continue;
            }
            DB::table('transaction_adjustments')->insert([
                'transaction_pk' => $transactionPk, // numeric PK
                'adjustment_type' => $adj['adjustment_type'],
                'amount' => $adj['amount'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Insert taxes only for new parent.
     */
    protected function insertTaxes($transactionPk, array $taxes): void
    {
        foreach ($taxes as $tax) {
            // Only insert if both tax_type and amount are present
            if (!isset($tax['tax_type'], $tax['amount']) || $tax['tax_type'] === '' || $tax['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed tax row', ['transaction_pk' => $transactionPk, 'row' => $tax]);
                continue;
            }
            DB::table('transaction_taxes')->insert([
                'transaction_pk' => $transactionPk, // numeric PK
                'tax_type' => $tax['tax_type'],
                'amount' => $tax['amount'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
