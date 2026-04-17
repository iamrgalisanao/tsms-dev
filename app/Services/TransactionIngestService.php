<?php
declare(strict_types=1);


namespace App\Services;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\DB;
use App\Services\DeadlockRetryService;

final readonly class TransactionIngestService
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
                            ->where('tenant_id', $parent['tenant_id'])
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
                        ->where('tenant_id', $parent['tenant_id'])
                        ->where('transaction_id', $parent['transaction_id'])
                        ->first();

                    if (!$existing) {
                        // Fuzzy Ghost Detection:
                        // We use the LIKE operator for the receipt search. 
                        // This handles cases where the POS provider sends receipts with hidden whitespace
                        // that the database index treats as a conflict but a strict '=' match misses.
                        $conflict = DB::table('transactions')
                            ->where('terminal_id', $parent['terminal_id'])
                            ->where('receipt_no', 'LIKE', (string)$parent['receipt_no'])
                            ->get()
                            ->first(function ($row) use ($parent) {
                                // Temporal proximity check (±24 hours)
                                $existingTs = strtotime((string)$row->transaction_timestamp);
                                $incomingTs = strtotime((string)$parent['transaction_timestamp']);
                                return abs($existingTs - $incomingTs) <= 86400; 
                            });

                        if ($conflict) {
                            Log::warning('TransactionIngestService: GHOST CONFLICT IDENTIFIED', [
                                'receipt_no' => $parent['receipt_no'],
                                'incoming_tx_id' => $parent['transaction_id'],
                                'existing_tx_id' => $conflict->transaction_id,
                                'existing_tenant' => $conflict->tenant_id,
                                'incoming_tenant' => $parent['tenant_id']
                            ]);

                            return [
                                'status' => 'failed',
                                'id' => null,
                                'transaction_id' => $parent['transaction_id'],
                                'terminal_id' => $parent['terminal_id'],
                                'message' => 'duplicate_receipt_conflict',
                                'details' => 'Receipt already exists on this terminal within a 24-hour window',
                            ];
                        }

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
        // Fallback: If hardware_id is missing but terminal_id is present, use terminal_id as hardware_id
        if ((!isset($payload['hardware_id']) || $payload['hardware_id'] === '') && isset($payload['terminal_id'])) {
            $payload['hardware_id'] = (string) $payload['terminal_id'];
        }

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
        $normalized = [
            'tenant_id' => $payload['tenant_id'],
            'terminal_id' => $payload['terminal_id'],
            'transaction_id' => $payload['transaction_id'],
            'hardware_id' => $payload['hardware_id'],
            'receipt_no' => $payload['receipt_no'] ?? null,
            'transaction_timestamp' => $payload['transaction_timestamp'],
            'gross_sales' => (float) $payload['gross_sales'],
            'net_sales' => (float) ($payload['net_sales'] ?? 0.0),
            'customer_code' => $payload['customer_code'],
            'promo_status' => $payload['promo_status'] ?? 'NONE',
            'payload_checksum' => $payload['payload_checksum'],
            'submission_uuid' => $payload['submission_uuid'] ?? null,
            'submission_timestamp' => $payload['submission_timestamp'] ?? null,
            'validation_status' => $payload['validation_status'] ?? 'PENDING',
            'created_at' => $payload['created_at'] ?? now(),
            'updated_at' => $payload['updated_at'] ?? now(),
        ];

        // Process Taxes
        if (!empty($payload['taxes']) && is_array($payload['taxes'])) {
            foreach ($payload['taxes'] as $tax) {
                $type = strtoupper(trim((string) ($tax['tax_type'] ?? '')));
                $amount = (float) ($tax['amount'] ?? 0.0);
                
                if ($type === 'VATABLE_SALES' || $type === 'VATABLE') {
                    $normalized['vatable_sales'] = $amount;
                } elseif ($type === 'SC_VAT_EXEMPT_SALES' || $type === 'VAT_EXEMPT_SALES' || $type === 'VATEXEMPT_SALES') {
                    $normalized['sc_vat_exempt_sales'] = $amount;
                } elseif ($type === 'VAT' || $type === 'VAT_AMOUNT') {
                    $normalized['vat_amount'] = $amount;
                }
            }
        }

        // Process Adjustments
        if (!empty($payload['adjustments']) && is_array($payload['adjustments'])) {
            foreach ($payload['adjustments'] as $adj) {
                $type = strtolower(trim((string) ($adj['adjustment_type'] ?? '')));
                $amount = (float) ($adj['amount'] ?? 0.0);

                if ($type === 'promo_discount') {
                    $normalized['promo_discount'] = $amount;
                } elseif ($type === 'senior_discount') {
                    $normalized['senior_discount'] = $amount;
                } elseif ($type === 'pwd_discount') {
                    $normalized['pwd_discount'] = $amount;
                } elseif ($type === 'service_charge' || $type === 'service_charge_distributed_to_employees') {
                    $normalized['service_charge'] = $amount;
                } elseif ($type === 'management_service_charge' || $type === 'service_charge_retained_by_management') {
                    $normalized['management_service_charge'] = $amount;
                }
            }
        }

        return $normalized;
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
