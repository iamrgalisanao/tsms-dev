<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final readonly class TransactionIngestService
{
    public function __construct(
        protected DeadlockRetryService $retryService
    ) {
    }

    /**
     * Ingest a transaction payload atomically with duplicate protection and deadlock retry.
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

                        if (! $transaction) {
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

                    if ($existing) {
                        return [
                            'status' => 'already_processed',
                            'id' => $existing->id,
                            'transaction_id' => $parent['transaction_id'],
                            'terminal_id' => $parent['terminal_id'],
                            'message' => 'already_processed',
                        ];
                    }

                    $conflict = $this->findReceiptConflict($parent);
                    if ($conflict) {
                        Log::warning('TransactionIngestService: duplicate receipt conflict identified', [
                            'receipt_no' => $parent['receipt_no'],
                            'incoming_tx_id' => $parent['transaction_id'],
                            'existing_tx_id' => $conflict->transaction_id,
                            'existing_tenant' => $conflict->tenant_id,
                            'incoming_tenant' => $parent['tenant_id'],
                        ]);

                        return [
                            'status' => 'duplicate',
                            'id' => $conflict->id,
                            'transaction_id' => $parent['transaction_id'],
                            'terminal_id' => $parent['terminal_id'],
                            'message' => 'duplicate_receipt_conflict',
                            'details' => 'Receipt already exists on this terminal within a 24-hour window',
                        ];
                    }

                    Log::warning('TransactionIngestService: insertOrIgnore returned 0 but no existing transaction found', $parent);

                    return [
                        'status' => 'failed',
                        'transaction_id' => $parent['transaction_id'],
                        'terminal_id' => $parent['terminal_id'],
                        'message' => 'insert ignored but no existing transaction found',
                    ];
                }, 1);
            });
        } catch (\Throwable $e) {
            Log::error('TransactionIngestService: ingest failed', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return [
                'status' => 'failed',
                'id' => null,
                'transaction_id' => $payload['transaction_id'] ?? null,
                'terminal_id' => $payload['terminal_id'] ?? null,
                'message' => 'ingest_failed',
                'details' => $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize an official transaction payload for DB persistence.
     */
    protected function normalizePayload(array $payload): array
    {
        if ((! isset($payload['hardware_id']) || $payload['hardware_id'] === '') && isset($payload['terminal_id'])) {
            $payload['hardware_id'] = (string) $payload['terminal_id'];
        }

        foreach ([
            'tenant_id',
            'terminal_id',
            'transaction_id',
            'hardware_id',
            'transaction_timestamp',
            'gross_sales',
            'customer_code',
            'payload_checksum',
        ] as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                throw new \InvalidArgumentException("{$field} is required in transaction payload");
            }
        }

        $normalized = [
            'tenant_id' => $payload['tenant_id'],
            'terminal_id' => $payload['terminal_id'],
            'transaction_id' => $payload['transaction_id'],
            'hardware_id' => $payload['hardware_id'],
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

        if (Schema::hasColumn('transactions', 'base_amount')) {
            $normalized['base_amount'] = (float) ($payload['base_amount'] ?? $payload['gross_sales']);
        }

        if (Schema::hasColumn('transactions', 'receipt_no')) {
            $normalized['receipt_no'] = $payload['receipt_no'] ?? null;
        }

        if (Schema::hasColumn('transactions', 'original_payload')) {
            $normalized['original_payload'] = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $this->applyTaxColumns($normalized, $payload['taxes'] ?? []);
        $this->applyAdjustmentColumns($normalized, $payload['adjustments'] ?? []);

        return $normalized;
    }

    protected function findReceiptConflict(array $parent): ?object
    {
        if (empty($parent['receipt_no']) || ! Schema::hasColumn('transactions', 'receipt_no')) {
            return null;
        }

        return DB::table('transactions')
            ->where('terminal_id', $parent['terminal_id'])
            ->whereRaw('TRIM(receipt_no) = TRIM(?)', [(string) $parent['receipt_no']])
            ->get()
            ->first(function ($row) use ($parent) {
                $existingTs = strtotime((string) $row->transaction_timestamp);
                $incomingTs = strtotime((string) $parent['transaction_timestamp']);

                if ($existingTs === false || $incomingTs === false) {
                    return false;
                }

                return abs($existingTs - $incomingTs) <= 86400;
            });
    }

    protected function applyTaxColumns(array &$normalized, array $taxes): void
    {
        foreach ($taxes as $tax) {
            $type = strtoupper(trim((string) ($tax['tax_type'] ?? '')));
            $amount = (float) ($tax['amount'] ?? 0.0);

            if (($type === 'VATABLE_SALES' || $type === 'VATABLE') && Schema::hasColumn('transactions', 'vatable_sales')) {
                $normalized['vatable_sales'] = $amount;
            } elseif (in_array($type, ['SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES'], true) && Schema::hasColumn('transactions', 'sc_vat_exempt_sales')) {
                $normalized['sc_vat_exempt_sales'] = $amount;
            } elseif (($type === 'VAT' || $type === 'VAT_AMOUNT') && Schema::hasColumn('transactions', 'vat_amount')) {
                $normalized['vat_amount'] = $amount;
            }
        }
    }

    protected function applyAdjustmentColumns(array &$normalized, array $adjustments): void
    {
        foreach ($adjustments as $adjustment) {
            $type = strtolower(trim((string) ($adjustment['adjustment_type'] ?? '')));
            $amount = (float) ($adjustment['amount'] ?? 0.0);

            if ($type === 'promo_discount' && Schema::hasColumn('transactions', 'promo_discount')) {
                $normalized['promo_discount'] = ($normalized['promo_discount'] ?? 0) + $amount;
            } elseif (in_array($type, ['senior_discount', 'senior_citizen_discount', 'senior'], true) && Schema::hasColumn('transactions', 'senior_discount')) {
                $normalized['senior_discount'] = ($normalized['senior_discount'] ?? 0) + $amount;
            } elseif (in_array($type, ['pwd_discount', 'pwd_citizen_discount', 'pwddiscount', 'pwd'], true) && Schema::hasColumn('transactions', 'pwd_discount')) {
                $normalized['pwd_discount'] = ($normalized['pwd_discount'] ?? 0) + $amount;
            } elseif (in_array($type, ['vip_card_discount', 'vip_discount', 'vip'], true) && Schema::hasColumn('transactions', 'vip_card_discount')) {
                $normalized['vip_card_discount'] = ($normalized['vip_card_discount'] ?? 0) + $amount;
            } elseif (in_array($type, ['employee_discount', 'employee'], true) && Schema::hasColumn('transactions', 'employee_discount')) {
                $normalized['employee_discount'] = ($normalized['employee_discount'] ?? 0) + $amount;
            } elseif (in_array($type, ['service_charge', 'service_charge_distributed_to_employees'], true) && Schema::hasColumn('transactions', 'service_charge')) {
                $normalized['service_charge'] = ($normalized['service_charge'] ?? 0) + $amount;
            } elseif (in_array($type, ['management_service_charge', 'service_charge_retained_by_management'], true) && Schema::hasColumn('transactions', 'management_service_charge')) {
                $normalized['management_service_charge'] = ($normalized['management_service_charge'] ?? 0) + $amount;
            }
        }
    }

    protected function insertAdjustments(int $transactionPk, array $adjustments): void
    {
        foreach ($adjustments as $adjustment) {
            if (! isset($adjustment['adjustment_type'], $adjustment['amount']) || $adjustment['adjustment_type'] === '' || $adjustment['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed adjustment row', [
                    'transaction_pk' => $transactionPk,
                    'row' => $adjustment,
                ]);
                continue;
            }

            $row = [
                'adjustment_type' => $adjustment['adjustment_type'],
                'amount' => $adjustment['amount'],
            ];

            $this->attachTransactionReference($row, 'transaction_adjustments', $transactionPk);

            if (Schema::hasColumn('transaction_adjustments', 'created_at')) {
                $row['created_at'] = now();
            }
            if (Schema::hasColumn('transaction_adjustments', 'updated_at')) {
                $row['updated_at'] = now();
            }

            DB::table('transaction_adjustments')->insert($row);
        }
    }

    protected function insertTaxes(int $transactionPk, array $taxes): void
    {
        foreach ($taxes as $tax) {
            if (! isset($tax['tax_type'], $tax['amount']) || $tax['tax_type'] === '' || $tax['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed tax row', [
                    'transaction_pk' => $transactionPk,
                    'row' => $tax,
                ]);
                continue;
            }

            $row = [
                'tax_type' => $tax['tax_type'],
                'amount' => $tax['amount'],
            ];

            $this->attachTransactionReference($row, 'transaction_taxes', $transactionPk);

            if (Schema::hasColumn('transaction_taxes', 'created_at')) {
                $row['created_at'] = now();
            }
            if (Schema::hasColumn('transaction_taxes', 'updated_at')) {
                $row['updated_at'] = now();
            }

            DB::table('transaction_taxes')->insert($row);
        }
    }

    protected function attachTransactionReference(array &$row, string $table, int $transactionPk): void
    {
        if (Schema::hasColumn($table, 'transaction_pk')) {
            $row['transaction_pk'] = $transactionPk;
        }

        if (Schema::hasColumn($table, 'transaction_id')) {
            $row['transaction_id'] = DB::table('transactions')
                ->where('id', $transactionPk)
                ->value('transaction_id');
        }
    }
}
