<?php

namespace App\Services;

use App\Models\PosTerminal;
use App\Models\TransactionIntake;
use App\Models\TransactionSubmission;
use App\Rules\UuidV4;
use App\Rules\ReceiptNumber;
use App\Support\Metrics;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionIntakeService
{
    protected PayloadChecksumService $checksumService;

    public function __construct(
        PayloadChecksumService $checksumService,
        private readonly IngestionQueueRouter $queueRouter,
        private readonly IngestionBackpressureService $backpressureService
    ) {
        $this->checksumService = $checksumService;
    }
    /**
     * Handle the intake of a TSMS transaction submission.
     *
     * @param Request $request
     * @return array
     */
    public function handleIntake(Request $request): array
    {
        return $this->handleOfficialIntake($request);
    }

    public function handleOfficialIntake(Request $request): array
    {
        // Nothing has attempted the protected downstream (the durable
        // persist / DB write) yet. Every early-return branch below
        // (batch-count guard, structural validation, hardware mismatch,
        // idempotency short-circuit, checksum failure, backpressure
        // rejection/degradation) leaves this false, so CircuitBreakerMiddleware
        // correctly excludes them from breaker recording. Flipped to true
        // immediately before the real persist attempt below.
        $request->attributes->set('circuit_breaker.downstream_attempted', false);

        $payload = $request->all();
        $sourceIp = $request->ip();
        $traceId = $request->header('X-Correlation-ID') ?? Str::uuid()->toString();
        $receivedAt = now();
        $terminal = $request->user();
        $tenantId = $terminal instanceof PosTerminal ? $terminal->tenant_id : ($payload['tenant_id'] ?? 0);
        $shardQueue = $this->queueRouter->intakeQueueForTenant($tenantId);
        $hardwareMismatch = null;

        // 0. Cheap batch-count guard, before structural validation builds
        // per-item wildcard rules and before any DB work. Deliberately runs
        // ahead of Stage 1 so an oversized batch cannot pay for validating
        // every item first.
        $maxBatchCount = (int) config('tsms.intake.max_batch_count');
        if ($maxBatchCount > 0) {
            $submittedForLimitCheck = $this->submittedTransactions($payload)[0];
            if (count($submittedForLimitCheck) > $maxBatchCount) {
                return [
                    'success' => false,
                    'http_status' => 422,
                    'message' => 'Batch transaction count exceeds the maximum allowed.',
                    'error_code' => 'BATCH_LIMIT_EXCEEDED',
                    'max_batch_count' => $maxBatchCount,
                    'correlation_id' => $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id'),
                ];
            }
        }

        // 1. Stage 1: Structural & Format Validation (Gatekeeping)
        $validator = Validator::make($payload, $this->officialStructuralRules($payload));

        $validator->after(function ($validator) use ($payload, $terminal, &$hardwareMismatch) {
            if (!$terminal instanceof PosTerminal) {
                $validator->errors()->add('authorization', 'Authenticated terminal context is required.');
                return;
            }

            [$transactions, $isBatch] = $this->submittedTransactions($payload);

            if ((int) ($payload['tenant_id'] ?? 0) !== (int) $terminal->tenant_id) {
                $validator->errors()->add('tenant_id', 'Terminal does not belong to the specified tenant.');
            }

            if ((int) ($payload['terminal_id'] ?? 0) !== (int) $terminal->id) {
                $validator->errors()->add('terminal_id', 'Authenticated token does not match terminal_id.');
            }

            $declaredCount = (int) ($payload['transaction_count'] ?? 0);
            $actualCount = count($transactions);

            if ($declaredCount > 0 && $actualCount > 0 && $declaredCount !== $actualCount) {
                $validator->errors()->add('transaction_count', 'transaction_count does not match submitted transaction payload.');
            }

            foreach ($transactions as $index => $transaction) {
                $prefix = $isBatch ? "transactions.{$index}" : 'transaction';
                $this->validateNestedTransactionContent($validator, is_array($transaction) ? $transaction : [], $prefix);
            }

            if ($validator->errors()->isEmpty()) {
                $hardwareMismatch = $this->findHardwareBindingMismatch($terminal, $transactions);
                if ($hardwareMismatch !== null) {
                    $validator->errors()->add('hardware_id', 'Transaction hardware_id does not match the authenticated terminal.');
                }
            }
        });

        if ($validator->fails()) {
            if ($hardwareMismatch !== null) {
                $this->persistRejection($payload, $validator->errors()->toArray(), $sourceIp, $traceId, $receivedAt, 'HARDWARE_ID_MISMATCH');

                return [
                    'success' => false,
                    'http_status' => 403,
                    'message' => 'Forbidden - hardware ID does not match terminal',
                    'error_code' => 'HARDWARE_ID_MISMATCH',
                    'errors' => $validator->errors()->toArray(),
                    'correlation_id' => $traceId,
                ];
            }

            $this->persistRejection($payload, $validator->errors()->toArray(), $sourceIp, $traceId, $receivedAt, 'STRUCTURAL_VALIDATION_FAILURE');
            
            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'Structural validation failed',
                'error_code' => 'STRUCTURAL_VALIDATION_FAILURE',
                'errors' => $validator->errors()->toArray(),
                'correlation_id' => $traceId,
            ];
        }

        $this->observeTransactionBoundary('after_structural_validation');

        // 2. Idempotency check: report the stored outcome for an existing submission_uuid.
        $existing = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->first();
        if ($existing) {
            return $this->existingIntakeResponse($existing, $payload, $traceId, $terminal);
        }

        $existingSubmission = TransactionSubmission::where('submission_uuid', $payload['submission_uuid'])->first();
        if ($existingSubmission) {
            return $this->existingLegacySubmissionResponse($existingSubmission, $payload, $traceId, $terminal);
        }

        $this->observeTransactionBoundary('after_idempotency_lookup');

        // 3. Stage 2: Cryptographic Integrity (Synchronous Checksum)
        $checksumResult = $this->checksumService->validateSubmissionChecksums($payload);
        if (!$checksumResult['valid']) {
            $this->persistRejection($payload, $checksumResult['errors'], $sourceIp, $traceId, $receivedAt, 'CRYPTOGRAPHIC_INTEGRITY_FAILURE');

            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'Cryptographic integrity check failed. Payload may have been tampered with or canonicalization logic is incorrect.',
                'error_code' => 'CRYPTOGRAPHIC_INTEGRITY_FAILURE',
                'errors' => $checksumResult['errors'],
                'hint' => 'Ensure you are using the V2.1/V2.2 canonicalization strategy (ksort + 2-decimal strings).',
                'correlation_id' => $traceId,
            ];
        }

        $this->observeTransactionBoundary('after_checksum_validation');

        Metrics::incr('intake.received_count');

        // 4. Proactive Backpressure Check (Shard-Aware Fail-Fast, intake + processing aggregate)
        $reusedProcessing = $request->attributes->get('backpressure.processing');
        $backpressure = $this->backpressureService->checkAggregate($tenantId, $reusedProcessing);

        if ($backpressure['enforced']) {
            if ($backpressure['degraded']) {
                return [
                    'success' => false,
                    'http_status' => $this->backpressureService->degradedStatus(),
                ] + $this->backpressureService->degradedPayload($backpressure, $traceId);
            }

            return [
                'success' => false,
                'http_status' => $this->backpressureService->rejectionStatus(),
            ] + $this->backpressureService->rejectionPayload($backpressure, $traceId);
        }

        $this->observeTransactionBoundary('after_backpressure_check');

        // 5. Persist raw intake
        try {
            // About to genuinely attempt the durable persist — from here on,
            // an outcome (success or failure) is meaningful for the circuit
            // breaker.
            $request->attributes->set('circuit_breaker.downstream_attempted', true);

            $intake = $this->persistQueuedIntake($payload, $terminal, $request, $sourceIp, $traceId, $receivedAt, $shardQueue);

            $pilotTenants = config('tsms.rollout.pilot_tenants', []);
            $isPilot = in_array($intake->tenant_id, $pilotTenants);

            Log::info('TransactionIntakeService: Intake queued asychronously', [
                'tenant_id' => $intake->tenant_id,
                'is_pilot' => $isPilot,
                'submission_uuid' => $intake->submission_uuid,
                'queue' => $shardQueue,
                'queued' => true,
            ]);

            // Performance: Intake Dispatch Latency (Sync path)
            $latency = now()->diffInMilliseconds($receivedAt);
            Metrics::timing('intake.dispatch_latency', $latency);
            Metrics::bucket('intake.dispatch_latency', $latency);
            Metrics::incr('intake.accepted_count');
            Metrics::incr("tenant.{$intake->tenant_id}.intake_count");

            return [
                'success' => true,
                'status' => 'queued',
                'http_status' => 202,
                'message' => 'Submission accepted',
                'submission_uuid' => $intake->submission_uuid,
                'intake_id' => $intake->id,
                'correlation_id' => $traceId,
                'retryable' => false,
            ];
        } catch (UniqueConstraintViolationException $e) {
            $existing = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->first();
            if ($existing) {
                return $this->existingIntakeResponse($existing, $payload, $traceId, $terminal);
            }

            return [
                'success' => false,
                'http_status' => 503,
                'message' => 'System unavailable',
                'error_code' => 'INTAKE_PERSISTENCE_FAILED',
                'correlation_id' => $traceId,
            ];
        } catch (\Exception $e) {
            Log::error('TransactionIntakeService: Persistence failed', [
                'error' => $e->getMessage(),
                'submission_uuid' => $payload['submission_uuid'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'http_status' => 503,
                'message' => 'System unavailable',
                'error_code' => 'INTAKE_PERSISTENCE_FAILED',
                'correlation_id' => $traceId,
            ];
        }
    }

    protected function officialStructuralRules(array $payload): array
    {
        $transactionCount = (int) ($payload['transaction_count'] ?? 0);
        $transactionPrefix = $transactionCount === 1 ? 'transaction' : 'transactions.*';

        $rules = [
            'submission_uuid' => ['required', 'string', new UuidV4()],
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer',
            'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64|regex:/^[0-9a-f]{64}$/i',
            $transactionPrefix . '.transaction_id' => ['required', 'string', new UuidV4()],
            $transactionPrefix . '.hardware_id' => 'required|string|max:255',
            $transactionPrefix . '.transaction_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            $transactionPrefix . '.gross_sales' => 'required|numeric|min:0',
            $transactionPrefix . '.net_sales' => 'required|numeric',
            $transactionPrefix . '.promo_status' => 'required|string|max:255',
            $transactionPrefix . '.customer_code' => 'required|string|max:255',
            $transactionPrefix . '.receipt_no' => ['required', new ReceiptNumber()],
            $transactionPrefix . '.payload_checksum' => 'required|string|min:64|max:64',
            $transactionPrefix . '.adjustments' => 'required|array|min:7',
            $transactionPrefix . '.adjustments.*.adjustment_type' => 'required|string|max:50',
            $transactionPrefix . '.adjustments.*.amount' => 'required|numeric',
            $transactionPrefix . '.taxes' => 'required|array|min:4',
            $transactionPrefix . '.taxes.*.tax_type' => 'required|string|max:20',
            $transactionPrefix . '.taxes.*.amount' => 'required|numeric',
        ];

        if ($transactionCount === 1) {
            $rules['transaction'] = 'required|array';
        } else {
            $rules['transactions'] = 'required|array|min:1';
        }

        return $rules;
    }

    protected function persistQueuedIntake(
        array $payload,
        PosTerminal $terminal,
        Request $request,
        string $sourceIp,
        string $traceId,
        \Carbon\Carbon $receivedAt,
        string $shardQueue
    ): TransactionIntake {
        $this->observeTransactionBoundary('before_durable_persist');

        $intake = DB::transaction(function () use ($payload, $terminal, $request, $sourceIp, $traceId, $receivedAt) {
            $intake = TransactionIntake::create([
                'submission_uuid' => $payload['submission_uuid'],
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'payload_checksum' => $payload['payload_checksum'],
                'payload' => $payload,
                'payload_size_bytes' => strlen($request->getContent()),
                'source_ip' => $sourceIp,
                'intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED,
                'trace_id' => $traceId,
                'received_at' => $receivedAt,
            ]);

            $intake->update([
                'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            return $intake->fresh();
        });

        $this->observeTransactionBoundary('after_durable_persist');
        $this->dispatchIntakeJob($intake, $shardQueue);

        return $intake;
    }

    protected function dispatchIntakeJob(TransactionIntake $intake, string $shardQueue): void
    {
        $this->observeTransactionBoundary('before_intake_dispatch');

        \App\Jobs\ProcessTransactionIntakeJob::dispatch($intake->id)
            ->onQueue($shardQueue)
            ->afterCommit();

        $this->observeTransactionBoundary('after_intake_dispatch');
    }

    protected function observeTransactionBoundary(string $stage): void
    {
    }

    protected function submittedTransactions(array $payload): array
    {
        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            return [$payload['transactions'], true];
        }

        if (isset($payload['transaction']) && is_array($payload['transaction'])) {
            return [[$payload['transaction']], false];
        }

        return [[], false];
    }

    protected function validateNestedTransactionContent($validator, array $transaction, string $prefix): void
    {
        $adjustments = isset($transaction['adjustments']) && is_array($transaction['adjustments'])
            ? $transaction['adjustments']
            : [];
        $taxes = isset($transaction['taxes']) && is_array($transaction['taxes'])
            ? $transaction['taxes']
            : [];

        $requiredAdjustmentTypes = [
            'promo_discount',
            'senior_discount',
            'pwd_discount',
            'vip_card_discount',
            'service_charge_distributed_to_employees',
            'service_charge_retained_by_management',
            'employee_discount',
        ];

        $presentAdjustmentTypes = array_filter(array_map(
            fn ($adjustment) => is_array($adjustment) ? ($adjustment['adjustment_type'] ?? null) : null,
            $adjustments
        ));
        $missingAdjustmentTypes = array_diff($requiredAdjustmentTypes, $presentAdjustmentTypes);

        if ($missingAdjustmentTypes !== []) {
            $validator->errors()->add(
                "{$prefix}.adjustments",
                'Missing required adjustment types: ' . implode(', ', $missingAdjustmentTypes)
            );
        }

        $requiredTaxTypes = ['VAT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES'];
        $presentTaxTypes = array_filter(array_map(
            fn ($tax) => is_array($tax) ? ($tax['tax_type'] ?? null) : null,
            $taxes
        ));
        $missingTaxTypes = array_diff($requiredTaxTypes, $presentTaxTypes);

        $vatAmount = $this->taxAmount($taxes, 'VAT');
        $vatableSalesAmount = $this->taxAmount($taxes, 'VATABLE_SALES');
        $scVatExemptAmount = $this->taxAmount($taxes, 'SC_VAT_EXEMPT_SALES');
        $isNonVat = $vatAmount == 0 && $vatableSalesAmount == 0 && $scVatExemptAmount > 0;

        if ($missingTaxTypes !== [] && !$isNonVat) {
            $validator->errors()->add(
                "{$prefix}.taxes",
                'Missing required tax types: ' . implode(', ', $missingTaxTypes)
            );
        }
    }

    protected function taxAmount(array $taxes, string $taxType): float
    {
        foreach ($taxes as $tax) {
            if (is_array($tax) && ($tax['tax_type'] ?? null) === $taxType) {
                return (float) ($tax['amount'] ?? 0);
            }
        }

        return 0.0;
    }

    protected function findHardwareBindingMismatch(?PosTerminal $terminal, array $transactions): ?array
    {
        $registeredHardwareId = trim((string) ($terminal?->serial_number ?? ''));

        if ($registeredHardwareId === '') {
            return [
                'registered_hardware_id' => null,
                'submitted_hardware_id' => null,
                'transaction_id' => null,
                'receipt_no' => null,
            ];
        }

        foreach ($transactions as $transaction) {
            $submittedHardwareId = trim((string) ($transaction['hardware_id'] ?? ''));

            if ($submittedHardwareId !== $registeredHardwareId) {
                return [
                    'registered_hardware_id' => $registeredHardwareId,
                    'submitted_hardware_id' => $submittedHardwareId,
                    'transaction_id' => $transaction['transaction_id'] ?? null,
                    'receipt_no' => $transaction['receipt_no'] ?? null,
                ];
            }
        }

        return null;
    }

    protected function persistRejection(array $payload, array $errors, string $sourceIp, string $traceId, \Carbon\Carbon $receivedAt, string $errorCode = 'LAYER_A_VALIDATION_FAILURE'): void
    {
        try {
            // Only persist if we have a submission_uuid to track it
            if (isset($payload['submission_uuid'])) {
                TransactionIntake::create([
                    'submission_uuid' => $payload['submission_uuid'],
                    'tenant_id' => auth()->user()->tenant_id ?? 0,
                    'terminal_id' => auth()->user()->id ?? 0,
                    'payload_checksum' => $payload['payload_checksum'] ?? 'NONE',
                    'payload' => $payload,
                    'payload_size_bytes' => strlen(json_encode($payload)),
                    'source_ip' => $sourceIp,
                    'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
                    'last_error_code' => $errorCode,
                    'last_error_message' => json_encode($errors),
                    'trace_id' => $traceId,
                    'received_at' => $receivedAt,
                ]);

                Metrics::incr('intake.rejected_count');
            }
        } catch (\Exception $e) {
            Log::warning('TransactionIntakeService: Failed to persist rejection audit', ['error' => $e->getMessage()]);
        }
    }

    protected function existingIntakeResponse(TransactionIntake $existing, array $payload, string $traceId, mixed $terminal): array
    {
        $data = [
            'submission_uuid' => $existing->submission_uuid,
            'intake_id' => $existing->id,
            'intake_status' => $existing->intake_status,
            'processing_status' => $existing->processing_status,
            'last_error_code' => $existing->last_error_code,
            'payload_checksum' => $existing->payload_checksum,
            'received_at' => optional($existing->received_at)->toISOString(),
        ];

        if (($terminal instanceof PosTerminal && (int) $existing->terminal_id !== (int) $terminal->id)
            || ! hash_equals((string) $existing->payload_checksum, (string) ($payload['payload_checksum'] ?? ''))) {
            return $this->idempotencyConflictResponse(
                (string) $existing->submission_uuid,
                $traceId,
                'Submission UUID already exists with a different terminal or payload. Correct the payload and resend with a new submission_uuid.',
                $data
            );
        }

        if ($existing->intake_status === TransactionIntake::INTAKE_STATUS_REJECTED) {
            return [
                'success' => false,
                'http_status' => 422,
                'message' => 'Submission was already rejected. Correct the payload and resend with a new submission_uuid.',
                'error_code' => $existing->last_error_code ?? 'SUBMISSION_ALREADY_REJECTED',
                'errors' => $this->decodeStoredErrors($existing->last_error_message),
                'correlation_id' => $traceId,
                'data' => $data,
            ];
        }

        return [
            'success' => true,
            'status' => strtolower($existing->intake_status),
            'http_status' => 202,
            'message' => 'Submission already accepted',
            'submission_uuid' => $existing->submission_uuid,
            'intake_id' => $existing->id,
            'correlation_id' => $traceId,
            'retryable' => false,
            'data' => $data,
        ];
    }

    protected function existingLegacySubmissionResponse(TransactionSubmission $existing, array $payload, string $traceId, mixed $terminal): array
    {
        $sameTerminal = $terminal instanceof PosTerminal && (int) $existing->terminal_id === (int) $terminal->id;
        $samePayload = hash_equals((string) $existing->payload_checksum, (string) ($payload['payload_checksum'] ?? ''))
            && (int) $existing->transaction_count === (int) ($payload['transaction_count'] ?? 0);

        if (!$sameTerminal || !$samePayload) {
            return $this->idempotencyConflictResponse(
                (string) $existing->submission_uuid,
                $traceId,
                'Submission UUID already exists with a different terminal or payload. Correct the payload and resend with a new submission_uuid.',
                [
                    'submission_uuid' => $existing->submission_uuid,
                    'tenant_id' => $existing->tenant_id,
                    'terminal_id' => $existing->terminal_id,
                    'status' => $existing->status,
                    'payload_checksum' => $existing->payload_checksum,
                    'transaction_count' => $existing->transaction_count,
                ]
            );
        }

        return [
            'success' => true,
            'status' => strtolower((string) $existing->status),
            'http_status' => 200,
            'message' => 'Submission already processed (idempotent)',
            'submission_uuid' => $existing->submission_uuid,
            'correlation_id' => $traceId,
            'retryable' => false,
            'data' => [
                'submission_uuid' => $existing->submission_uuid,
                'transaction_count' => $existing->transaction_count,
                'status' => $existing->status,
            ],
        ];
    }

    protected function idempotencyConflictResponse(string $submissionUuid, string $traceId, string $message, array $conflict): array
    {
        return [
            'success' => false,
            'http_status' => 409,
            'message' => $message,
            'error_code' => 'IDEMPOTENCY_CONFLICT',
            'submission_uuid' => $submissionUuid,
            'correlation_id' => $traceId,
            'conflict' => $conflict,
        ];
    }

    protected function decodeStoredErrors(?string $storedErrors): ?array
    {
        if ($storedErrors === null || $storedErrors === '') {
            return null;
        }

        $decoded = json_decode($storedErrors, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : [$storedErrors];
    }

}
