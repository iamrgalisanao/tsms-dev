<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PosTerminal;
use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransactionIngestService
{
    private const UNIQUE_VIOLATION_MARKER = '__unique_violation';

    public function __construct(
        protected DeadlockRetryService $retryService,
        private readonly ProviderTimestampNormalizer $timestampNormalizer
    ) {
    }

    /**
     * Ingest a transaction payload atomically with duplicate protection and deadlock retry.
     *
     * Circuit breaker (research R3): this runs inside a queue job that was
     * never admitted through CircuitBreaker::isAvailable(), so it holds no
     * half-open probe generation. recordSuccess(null) would reset() an open
     * or half-open breaker, force-closing it and bypassing the probes, so
     * ingest never records success. Recovery is decided only by
     * CircuitBreakerMiddleware's generation-tracked probes. Infrastructure
     * failures still call recordFailure(); data-level and classified
     * failures only increment ingest.data_failure_count.
     *
     * @param  string|null  $correlationId  Submission trace id, logged as
     *                                      correlation_id (research R6).
     */
    public function ingest(array $payload, ?string $correlationId = null): array
    {
        try {
            $parent = $this->normalizePayload($payload);
        } catch (\InvalidArgumentException $e) {
            // Client data-quality issue, not an infrastructure failure — must
            // not count against the ingestion circuit breaker.
            // ingest.data_failure_count includes invalid payloads.
            Metrics::incr('ingest.data_failure_count');
            Log::warning('TransactionIngestService: invalid payload', array_merge(
                $this->logRef($payload, $correlationId),
                ['error' => $e->getMessage()]
            ));

            return [
                'status' => 'failed',
                'id' => null,
                'transaction_id' => $payload['transaction_id'] ?? null,
                'terminal_id' => $payload['terminal_id'] ?? null,
                'message' => 'invalid_payload',
                'details' => $e->getMessage(),
            ];
        }

        $circuitBreaker = new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY);

        try {
            $receiptConflict = $this->findReceiptConflict($parent);
            if ($receiptConflict) {
                return $this->receiptConflictResult($receiptConflict, $parent, $correlationId);
            }

            $result = $this->retryService->withDeadlockRetry(function () use ($payload, $parent, $correlationId) {
                return DB::transaction(function () use ($payload, $parent, $correlationId) {
                    $receiptConflict = $this->findReceiptConflict($parent);
                    if ($receiptConflict) {
                        return $this->receiptConflictResult($receiptConflict, $parent, $correlationId);
                    }

                    try {
                        $inserted = $this->insertTransactionParent($parent);
                    } catch (QueryException $e) {
                        if (($e->errorInfo[1] ?? null) !== 1062) {
                            throw $e;
                        }

                        // Duplicate key: classify after the transaction
                        // closure returns, so the lookups see rows committed
                        // by a concurrent transaction (research A2).
                        return [self::UNIQUE_VIOLATION_MARKER => self::uniqueIndexFromErrorInfo($e->errorInfo)];
                    }

                    if ($inserted === 1) {
                        $transaction = DB::table('transactions')
                            ->where('tenant_id', $parent['tenant_id'])
                            ->where('transaction_id', $parent['transaction_id'])
                            ->first();

                        if (! $transaction) {
                            Log::error('TransactionIngestService: Inserted but not found', $this->logRef($parent, $correlationId));

                            return [
                                'status' => 'failed',
                                'id' => null,
                                'transaction_id' => $parent['transaction_id'],
                                'terminal_id' => $parent['terminal_id'],
                                'message' => 'inserted but not found',
                            ];
                        }

                        $this->insertAdjustments($transaction->id, $payload['adjustments'] ?? [], $correlationId);
                        $this->insertTaxes($transaction->id, $payload['taxes'] ?? [], $correlationId);

                        return [
                            'status' => 'accepted',
                            'id' => $transaction->id,
                            'transaction_id' => $transaction->transaction_id,
                            'terminal_id' => $transaction->terminal_id,
                            'message' => 'created',
                        ];
                    }

                    // Test seams may return 0 to simulate an ignored insert;
                    // treat it as a duplicate with no index name (research A3).
                    return [self::UNIQUE_VIOLATION_MARKER => null];
                }, 1);
            }, wrapInTransaction: false);

            if (array_key_exists(self::UNIQUE_VIOLATION_MARKER, $result)) {
                $result = $this->classifyUniqueViolation($result[self::UNIQUE_VIOLATION_MARKER], $parent, $correlationId);
            }

            if (in_array($result['message'] ?? null, ['cross_tenant_transaction_id_collision', 'unique_constraint_violation'], true)) {
                Metrics::incr('ingest.data_failure_count');
            }

            return $result;
        } catch (\Throwable $e) {
            $reference = (string) Str::uuid();

            // Never log getMessage() or the payload: a QueryException
            // message carries the SQL bindings (research A4).
            $exceptionContext = self::exceptionLogContext($e);

            Log::error('TransactionIngestService: ingest failed', array_merge(
                $exceptionContext,
                ['reference' => $reference],
                $this->logRef($payload, $correlationId)
            ));

            // Only a genuine infrastructure-level failure (DB unreachable,
            // timed out, or deadlock-exhausted after retry) should trip the
            // shared ingestion circuit breaker. A data/constraint-level
            // exception means a value slipped past validation and hit the
            // DB directly — that's a validation gap, not a downstream
            // outage, and must not falsely open the breaker for every
            // tenant.
            if (self::isInfrastructureFailure($e)) {
                $circuitBreaker->recordFailure();
            } else {
                Metrics::incr('ingest.data_failure_count');
                Log::error('TransactionIngestService: data/constraint-level exception reached the DB despite passing validation — check for a validation gap', array_merge(
                    $exceptionContext,
                    ['reference' => $reference],
                    $this->logRef($payload, $correlationId)
                ));
            }

            return [
                'status' => 'failed',
                'id' => null,
                'transaction_id' => $payload['transaction_id'] ?? null,
                'terminal_id' => $payload['terminal_id'] ?? null,
                'message' => 'ingest_failed',
                // Never leak raw exception text to a terminal-facing field
                // (this return value ultimately reaches
                // TransactionIntake.last_error_message and, from there,
                // SubmissionStatusController::show()). The full message is
                // already logged above under the same reference.
                'details' => 'An internal error occurred while processing this transaction. Reference: ' . $reference,
            ];
        }
    }

    /**
     * True when $e represents a genuine infrastructure-level DB failure
     * (connection refused/lost, timeout, deadlock-exhausted after retry)
     * rather than a data/constraint-level failure (a value that slipped
     * past validation and hit a DB constraint, encoding error, or
     * truncation). Follows the same SQLSTATE/message-inspection idiom used
     * by DeadlockRetryService::withDeadlockRetry() for classifying
     * QueryExceptions.
     *
     * Public static so other call sites that need to decide whether an
     * exception should trip the shared ingestion circuit breaker (e.g.
     * ProcessTransactionIntakeJob's outer catch) can reuse this exact
     * classification instead of duplicating the SQLSTATE-matching logic.
     */
    public static function isInfrastructureFailure(\Throwable $e): bool
    {
        if (! $e instanceof \Illuminate\Database\QueryException) {
            return true;
        }

        // SQLSTATE class '23' = integrity constraint violation (duplicate
        // key, FK, not-null), '22' = data exception (invalid value,
        // string data right truncation). Both mean a value reached the DB
        // despite passing validation — not that the DB itself is unhealthy.
        $sqlState = (string) $e->getCode();
        if (str_starts_with($sqlState, '23') || str_starts_with($sqlState, '22')) {
            return false;
        }

        $message = $e->getMessage();
        if (
            str_contains($message, 'SQLSTATE[23') ||
            str_contains($message, 'SQLSTATE[22') ||
            str_contains($message, 'Data too long for column') ||
            str_contains($message, 'Incorrect string value') ||
            str_contains($message, 'Incorrect datetime value')
        ) {
            return false;
        }

        return true;
    }

    /**
     * Reference-only log context (research R7): never the payload, the row
     * being inserted, or original_payload.
     */
    private function logRef(array $data, ?string $correlationId): array
    {
        return [
            'tenant_id' => $data['tenant_id'] ?? null,
            'terminal_id' => $data['terminal_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'receipt_no' => $data['receipt_no'] ?? null,
            'submission_uuid' => $data['submission_uuid'] ?? null,
            'correlation_id' => $correlationId,
        ];
    }

    protected function receiptConflictResult(object $receiptConflict, array $parent, ?string $correlationId = null): array
    {
        if (hash_equals(
            (string) ($receiptConflict->payload_checksum ?? ''),
            (string) ($parent['payload_checksum'] ?? '')
        )) {
            return [
                'status' => 'already_processed',
                'id' => $receiptConflict->id,
                'transaction_id' => $receiptConflict->transaction_id,
                'terminal_id' => $receiptConflict->terminal_id,
                'message' => 'already_processed',
            ];
        }

        Log::warning('TransactionIngestService: duplicate receipt conflict identified', array_merge(
            $this->logRef($parent, $correlationId),
            [
                'incoming_tx_id' => $parent['transaction_id'],
                'existing_tx_id' => $receiptConflict->transaction_id,
                'existing_tenant' => $receiptConflict->tenant_id,
                'incoming_tenant' => $parent['tenant_id'],
            ]
        ));

        return [
            'status' => 'duplicate',
            'id' => $receiptConflict->id,
            'transaction_id' => $parent['transaction_id'],
            'terminal_id' => $parent['terminal_id'],
            'message' => 'duplicate_receipt_conflict',
            'details' => 'Receipt already exists on this terminal within a 24-hour window',
        ];
    }

    protected function insertTransactionParent(array $parent): int
    {
        DB::table('transactions')->insert($parent);

        return 1;
    }

    /**
     * Classify a duplicate-key outcome with ordered, schema-independent
     * lookups (research A1). Runs outside the transaction closure (A2).
     * The index name is used only for details and logs.
     */
    protected function classifyUniqueViolation(?string $index, array $parent, ?string $correlationId = null): array
    {
        // (1a) Same tenant already holds this transaction_id.
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

        // (1b) Another tenant holds this transaction_id. Never return the
        // other tenant's row id.
        $foreign = DB::table('transactions')
            ->select('id', 'tenant_id')
            ->where('transaction_id', $parent['transaction_id'])
            ->first();

        if ($foreign) {
            Log::warning('TransactionIngestService: cross-tenant transaction_id collision', array_merge(
                $this->logRef($parent, $correlationId),
                [
                    'incoming_tenant_id' => $parent['tenant_id'],
                    'existing_tenant_id' => $foreign->tenant_id,
                    'index' => $index,
                ]
            ));
            Metrics::incr('ingest.cross_tenant_collision_count');

            return [
                'status' => 'failed',
                'id' => null,
                'transaction_id' => $parent['transaction_id'],
                'terminal_id' => $parent['terminal_id'],
                'message' => 'cross_tenant_transaction_id_collision',
                'details' => 'transaction_id is already registered to another tenant',
            ];
        }

        // (2) Receipt rule (keeps same-checksum -> already_processed).
        $receiptConflict = $this->findReceiptConflict($parent);
        if ($receiptConflict) {
            return $this->receiptConflictResult($receiptConflict, $parent, $correlationId);
        }

        // (3) Some other unique index.
        Log::warning('TransactionIngestService: unique constraint violation not matched by lookups', array_merge(
            $this->logRef($parent, $correlationId),
            ['index' => $index ?? 'unknown']
        ));

        return [
            'status' => 'failed',
            'id' => null,
            'transaction_id' => $parent['transaction_id'],
            'terminal_id' => $parent['terminal_id'],
            'message' => 'unique_constraint_violation',
            'details' => $index ?? 'unknown',
        ];
    }

    /**
     * Extract the index name from a MySQL 1062 driver message
     * ("Duplicate entry '...' for key 'table.index'"). Reads errorInfo[2]
     * only, never getMessage() (which carries SQL bindings).
     */
    protected static function uniqueIndexFromErrorInfo(?array $errorInfo): ?string
    {
        $driverMessage = (string) ($errorInfo[2] ?? '');

        if (! preg_match("/for key '([^']+)'\\s*$/", $driverMessage, $matches)) {
            return null;
        }

        $key = $matches[1];
        $dot = strrpos($key, '.');

        return $dot === false ? $key : substr($key, $dot + 1);
    }

    /**
     * Log-safe exception context: class, SQLSTATE and driver code only.
     */
    protected static function exceptionLogContext(\Throwable $e): array
    {
        $errorInfo = $e instanceof QueryException && is_array($e->errorInfo) ? $e->errorInfo : [];

        return [
            'exception_class' => $e::class,
            'sqlstate' => isset($errorInfo[0]) ? (string) $errorInfo[0] : (string) $e->getCode(),
            'driver_code' => $errorInfo[1] ?? null,
        ];
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
            'net_sales',
            'customer_code',
            'payload_checksum',
        ] as $field) {
            if (! isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                throw new \InvalidArgumentException("{$field} is required in transaction payload");
            }
        }

        $terminal = PosTerminal::with('provider')->find((int) $payload['terminal_id']);
        if (! $terminal) {
            throw new \InvalidArgumentException("terminal_id {$payload['terminal_id']} was not found");
        }

        $normalizedTransactionTimestamp = $this->timestampNormalizer->normalize(
            (string) $payload['transaction_timestamp'],
            $terminal
        );
        $normalizedSubmissionTimestamp = isset($payload['submission_timestamp']) && $payload['submission_timestamp'] !== null && $payload['submission_timestamp'] !== ''
            ? $this->timestampNormalizer->normalize((string) $payload['submission_timestamp'], $terminal)
            : null;

        $normalized = [
            'tenant_id' => $payload['tenant_id'],
            'terminal_id' => $payload['terminal_id'],
            'transaction_id' => $payload['transaction_id'],
            'hardware_id' => $payload['hardware_id'],
            'transaction_timestamp' => $normalizedTransactionTimestamp,
            'gross_sales' => (float) $payload['gross_sales'],
            'net_sales' => (float) $payload['net_sales'],
            'customer_code' => $payload['customer_code'],
            'promo_status' => $payload['promo_status'] ?? 'NONE',
            'payload_checksum' => $payload['payload_checksum'],
            'submission_uuid' => $payload['submission_uuid'] ?? null,
            'submission_timestamp' => $normalizedSubmissionTimestamp,
            'validation_status' => $payload['validation_status'] ?? 'PENDING',
            'created_at' => $payload['created_at'] ?? now(),
            'updated_at' => $payload['updated_at'] ?? now(),
        ];

        if (Schema::hasColumn('transactions', 'base_amount')) {
            $normalized['base_amount'] = (float) ($payload['base_amount'] ?? $payload['gross_sales']);
        }

        if (Schema::hasColumn('transactions', 'receipt_no')) {
            $normalized['receipt_no'] = isset($payload['receipt_no']) ? trim((string) $payload['receipt_no']) : null;
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

        $ts = strtotime((string) $parent['transaction_timestamp']);

        if ($ts === false) {
            return null;
        }

        // Sargable on (tenant_id, terminal_id, receipt_no[, …]) indexes (staging
        // EXPLAIN used ux_tx_tenant_terminal_receipt_date_unique; schemas differ by
        // environment). Upper bound is exclusive +86401 because
        // the column is timestamp(3) and strtotime() drops milliseconds; the PHP
        // closure below remains the authoritative ±24h check.
        return DB::table('transactions')
            ->where('tenant_id', $parent['tenant_id'])
            ->where('terminal_id', $parent['terminal_id'])
            ->where('receipt_no', trim((string) $parent['receipt_no']))
            ->where('transaction_timestamp', '>=', date('Y-m-d H:i:s', $ts - 86400))
            ->where('transaction_timestamp', '<', date('Y-m-d H:i:s', $ts + 86401))
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

    protected function insertAdjustments(int $transactionPk, array $adjustments, ?string $correlationId = null): void
    {
        foreach ($adjustments as $index => $adjustment) {
            if (! isset($adjustment['adjustment_type'], $adjustment['amount']) || $adjustment['adjustment_type'] === '' || $adjustment['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed adjustment row', [
                    'transaction_pk' => $transactionPk,
                    'row_index' => $index,
                    'correlation_id' => $correlationId,
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

    protected function insertTaxes(int $transactionPk, array $taxes, ?string $correlationId = null): void
    {
        foreach ($taxes as $index => $tax) {
            if (! isset($tax['tax_type'], $tax['amount']) || $tax['tax_type'] === '' || $tax['amount'] === null) {
                Log::warning('TransactionIngestService: Skipping malformed tax row', [
                    'transaction_pk' => $transactionPk,
                    'row_index' => $index,
                    'correlation_id' => $correlationId,
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
