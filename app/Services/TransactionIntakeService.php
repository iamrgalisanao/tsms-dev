<?php

namespace App\Services;

use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Models\TransactionSubmission;
use App\Rules\ReceiptNumber;
use App\Rules\UuidV4;
use App\Support\LogContext;
use App\Support\Metrics;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionIntakeService
{
    /**
     * Format accepted for transaction_timestamp. The capture groups feed the
     * raw range/checkdate check in assessTransactionTimestamp() (US5).
     */
    public const TRANSACTION_TIMESTAMP_REGEX = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?Z?$/';

    public const INVALID_TRANSACTION_TIMESTAMP = 'INVALID_TRANSACTION_TIMESTAMP';

    public const FUTURE_TRANSACTION_TIMESTAMP = 'FUTURE_TRANSACTION_TIMESTAMP';

    protected PayloadChecksumService $checksumService;

    protected SkewRankingService $skewRanking;

    public function __construct(
        PayloadChecksumService $checksumService,
        private readonly IngestionQueueRouter $queueRouter,
        private readonly IngestionBackpressureService $backpressureService,
        ?SkewRankingService $skewRanking = null
    ) {
        $this->checksumService = $checksumService;
        // Optional with a container-resolved fallback (rather than a plain
        // constructor-promoted required param) so this new WU4 dependency
        // does not force every existing 3-arg `new TransactionIntakeService(...)`
        // call site and anonymous-subclass `parent::__construct(...)` call in
        // the test suite (several, across ingestion feature tests) to be
        // rewritten just to add a 4th argument they have no reason to care
        // about.
        $this->skewRanking = $skewRanking ?? app(SkewRankingService::class);
    }

    /**
     * Handle the intake of a TSMS transaction submission.
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
        // Read only the normalized request attribute set by
        // AttachCorrelationId (canonical 4-tier precedence: existing
        // attribute > X-Request-Id > X-Correlation-ID compatibility
        // fallback > generated UUID). The UUID fallback here is defensive
        // only, for direct-service-call test/CLI contexts that bypass the
        // middleware pipeline entirely — it never re-derives from raw
        // headers.
        $traceId = $request->attributes->get('correlation_id') ?: (string) Str::uuid();
        $receivedAt = now();
        $terminal = $request->user();
        $tenantId = $terminal instanceof PosTerminal ? $terminal->tenant_id : ($payload['tenant_id'] ?? 0);
        $shardQueue = $this->queueRouter->intakeQueueForTenant($tenantId);
        $hardwareMismatch = null;
        // US5: per-transaction timestamp assessments keyed by validation
        // prefix, filled in the after() hook below.
        $timestampFindings = [];
        $timestampOnlyRejection = false;

        // 0. Cheap batch-count guard, before structural validation builds
        // per-item wildcard rules and before any DB work. Deliberately runs
        // ahead of Stage 1 so an oversized batch cannot pay for validating
        // every item first.
        $maxBatchCount = (int) config('tsms.intake.max_batch_count');
        if ($maxBatchCount > 0) {
            $submittedForLimitCheck = $this->submittedTransactions($payload)[0];
            if (count($submittedForLimitCheck) > $maxBatchCount) {
                // WU4 (T053 remainder): rejection-reason counter, closing
                // the "batch-size" reason plan.md's WU4 prose names
                // alongside payload-size/fairness/backpressure/circuit
                // breaker. Metrics::incr() swallows its own failures, so
                // this can never affect the 422 response below.
                Metrics::incr('ingestion.rejected.batch_size');

                return [
                    'success' => false,
                    'http_status' => 422,
                    'message' => 'Batch transaction count exceeds the maximum allowed.',
                    'error_code' => 'BATCH_LIMIT_EXCEEDED',
                    'max_batch_count' => $maxBatchCount,
                    'correlation_id' => $traceId,
                ];
            }
        }

        // 1. Stage 1: Structural & Format Validation (Gatekeeping)
        $validator = Validator::make($payload, $this->officialStructuralRules($payload));

        $validator->after(function ($validator) use ($payload, $terminal, $receivedAt, $traceId, &$hardwareMismatch, &$timestampFindings, &$timestampOnlyRejection) {
            if (! $terminal instanceof PosTerminal) {
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

                if (is_array($transaction)) {
                    $finding = $this->assessTransactionTimestamp($transaction, $terminal, $receivedAt, $traceId);
                    if ($finding !== null) {
                        $timestampFindings[$prefix] = $finding;
                    }
                }
            }

            // Hardware binding is decided before enforce-mode timestamp
            // errors are added, so a bad timestamp never hides a hardware
            // mismatch: HARDWARE_ID_MISMATCH (403) keeps precedence exactly
            // as before US5.
            if ($validator->errors()->isEmpty()) {
                $hardwareMismatch = $this->findHardwareBindingMismatch($terminal, $transactions);
                if ($hardwareMismatch !== null) {
                    $validator->errors()->add('hardware_id', 'Transaction hardware_id does not match the authenticated terminal.');
                }
            }

            // US5 enforce mode (research A7): timestamp errors join the bag
            // unless the hardware path already owns the response. Their
            // specific code is used only when they are the only errors;
            // otherwise STRUCTURAL_VALIDATION_FAILURE wins.
            if ($hardwareMismatch === null && $this->timestampsEnforced()) {
                $rejections = array_filter($timestampFindings, fn (array $f) => $f['code'] !== null);
                if ($rejections !== []) {
                    $timestampOnlyRejection = $validator->errors()->isEmpty();
                    foreach ($rejections as $prefix => $finding) {
                        $validator->errors()->add("{$prefix}.transaction_timestamp", $finding['message']);
                    }
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

            if ($timestampOnlyRejection) {
                $errorCode = $this->timestampRejectionCode($timestampFindings);
                $this->persistRejection($payload, $validator->errors()->toArray(), $sourceIp, $traceId, $receivedAt, $errorCode);

                return [
                    'success' => false,
                    'http_status' => 422,
                    'message' => 'Transaction timestamp validation failed',
                    'error_code' => $errorCode,
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

        // US5 observe mode: count and log what enforce mode would have
        // rejected at this exact point, then carry on unchanged.
        if (! $this->timestampsEnforced()) {
            $this->recordWouldRejectTimestamps($timestampFindings, $terminal, $traceId);
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
        if (! $checksumResult['valid']) {
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
            $lateContext = $this->recordLateArrivals($timestampFindings, $receivedAt);

            Log::info('TransactionIntakeService: Intake queued asychronously', LogContext::ingestion([
                'tenant_id' => $intake->tenant_id,
                'terminal_id' => $intake->terminal_id,
                'route' => 'transactions.official',
                'shard' => $this->queueRouter->intakeShardIndexForTenant($intake->tenant_id),
                'correlation_id' => $traceId,
                'decision' => 'queued',
            ], [
                'is_pilot' => $isPilot,
                'submission_uuid' => $intake->submission_uuid,
                'queue' => $shardQueue,
                'queued' => true,
            ] + $lateContext));

            // Performance: Intake Dispatch Latency (Sync path)
            $latency = now()->diffInMilliseconds($receivedAt);
            Metrics::timing('intake.dispatch_latency', $latency);
            Metrics::bucket('intake.dispatch_latency', $latency);
            Metrics::incr('intake.accepted_count');
            Metrics::incr("tenant.{$intake->tenant_id}.intake_count");

            // WU4 (T053 remainder): bounded "top-N talkers" ranking,
            // recorded at the same call site as the unbounded per-tenant
            // counter above. Unlike that counter (one new Cache key per
            // distinct tenant ID, forever), this is a capped Redis sorted
            // set (see SkewRankingService/tsms.metrics.skew config) that
            // never grows past its configured member cap, added here
            // specifically to support a future "who are the top N
            // tenants/terminals right now" read without unbounded per
            // -tenant/terminal cardinality.
            $this->skewRanking->recordTenant($intake->tenant_id);
            $this->skewRanking->recordTerminal($intake->terminal_id);

            return $this->acceptedIntakeResponse(
                $intake,
                $payload,
                $traceId,
                'Official submission accepted: '.(int) $payload['transaction_count'].' pending, 0 failed'
            );
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
            Log::error('TransactionIntakeService: Persistence failed', LogContext::ingestion([
                'tenant_id' => $tenantId,
                'route' => 'transactions.official',
                'correlation_id' => $traceId,
                'decision' => 'persistence_failed',
            ], [
                'error' => $e->getMessage(),
                'submission_uuid' => $payload['submission_uuid'] ?? 'unknown',
            ]));

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
            'submission_uuid' => ['required', 'string', new UuidV4],
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer',
            'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64|regex:/^[0-9a-f]{64}$/i',
            $transactionPrefix.'.transaction_id' => ['required', 'string', new UuidV4],
            $transactionPrefix.'.hardware_id' => 'required|string|max:255',
            $transactionPrefix.'.transaction_timestamp' => ['required', 'string', 'regex:'.self::TRANSACTION_TIMESTAMP_REGEX],
            $transactionPrefix.'.gross_sales' => 'required|numeric|min:0',
            $transactionPrefix.'.net_sales' => 'required|numeric',
            $transactionPrefix.'.promo_status' => 'required|string|max:255',
            $transactionPrefix.'.customer_code' => 'required|string|max:255',
            $transactionPrefix.'.receipt_no' => ['required', new ReceiptNumber],
            $transactionPrefix.'.payload_checksum' => 'required|string|min:64|max:64',
            $transactionPrefix.'.adjustments' => 'required|array|min:7',
            $transactionPrefix.'.adjustments.*.adjustment_type' => 'required|string|max:50',
            $transactionPrefix.'.adjustments.*.amount' => 'required|numeric',
            $transactionPrefix.'.taxes' => 'required|array|min:4',
            $transactionPrefix.'.taxes.*.tax_type' => 'required|string|max:20',
            $transactionPrefix.'.taxes.*.amount' => 'required|numeric',
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

        \App\Jobs\ProcessTransactionIntakeJob::dispatch($intake->id, $intake->trace_id)
            ->onQueue($shardQueue)
            ->afterCommit();

        $this->observeTransactionBoundary('after_intake_dispatch');
    }

    protected function observeTransactionBoundary(string $stage): void {}

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
                'Missing required adjustment types: '.implode(', ', $missingAdjustmentTypes)
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

        if ($missingTaxTypes !== [] && ! $isNonVat) {
            $validator->errors()->add(
                "{$prefix}.taxes",
                'Missing required tax types: '.implode(', ', $missingTaxTypes)
            );
        }
    }

    /**
     * US5 timestamp sanity (research R4 + A7). Returns null when the value is
     * not a string matching the format regex (the structural rule reports
     * that; nothing is parsed). Otherwise returns
     * ['code' => ?string, 'message' => ?string, 'normalized' => ?Carbon,
     *  'transaction_id' => mixed]. The raw Y/m/d/H/i/s ranges and checkdate()
     * are checked before any Carbon parse, because Carbon silently rolls
     * 0000-00-00 and 02-30 over into valid dates.
     */
    protected function assessTransactionTimestamp(array $transaction, PosTerminal $terminal, \Carbon\Carbon $receivedAt, ?string $traceId = null): ?array
    {
        $raw = $transaction['transaction_timestamp'] ?? null;
        if (! is_string($raw) || preg_match(self::TRANSACTION_TIMESTAMP_REGEX, $raw, $m) !== 1) {
            return null;
        }

        $finding = [
            'code' => null,
            'message' => null,
            'normalized' => null,
            'transaction_id' => $transaction['transaction_id'] ?? null,
        ];
        $invalid = fn (string $message) => ['code' => self::INVALID_TRANSACTION_TIMESTAMP, 'message' => $message] + $finding;

        [$year, $month, $day, $hour, $minute, $second] = array_map('intval', array_slice($m, 1, 6));
        if ($year < 1 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return $invalid('Transaction timestamp is not a valid calendar date and time.');
        }

        try {
            $normalized = \Carbon\Carbon::createFromFormat(
                'Y-m-d H:i:s',
                app(ProviderTimestampNormalizer::class)->normalize($raw, $terminal),
                'UTC'
            );
        } catch (\InvalidArgumentException|\Carbon\Exceptions\Exception) {
            // Genuine parse failure of the submitted value.
            return $invalid('Transaction timestamp could not be parsed.');
        } catch (\Throwable $e) {
            // Infrastructure or unexpected failure (e.g. provider load hit a
            // QueryException): fail open so an outage never rejects or
            // flags valid sales. No message/bindings are logged.
            Metrics::incr('intake.timestamp_check_error');
            Log::warning('TransactionIntakeService: transaction timestamp check failed open', LogContext::ingestion([
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'route' => 'transactions.official',
                'correlation_id' => $traceId,
                'decision' => 'timestamp_check_failed_open',
            ], [
                'transaction_id' => $finding['transaction_id'],
                'exception_class' => $e::class,
            ]));

            return null;
        }

        $finding['normalized'] = $normalized;

        $earliest = $this->earliestTransactionTimestamp();
        if ($earliest !== null && $normalized->lt($earliest)) {
            return ['code' => self::INVALID_TRANSACTION_TIMESTAMP, 'message' => 'Transaction timestamp is before the earliest accepted date.'] + $finding;
        }

        $toleranceMinutes = max(0, (int) config('ingestion.timestamps.future_tolerance_minutes', 1440));
        if ($normalized->gt($receivedAt->copy()->addMinutes($toleranceMinutes))) {
            return ['code' => self::FUTURE_TRANSACTION_TIMESTAMP, 'message' => 'Transaction timestamp is too far in the future.'] + $finding;
        }

        return $finding;
    }

    protected function earliestTransactionTimestamp(): ?\Carbon\Carbon
    {
        $configured = trim((string) config('ingestion.timestamps.earliest', '2020-01-01'));
        if ($configured === '') {
            // Empty INGESTION_TS_EARLIEST means no earliest bound.
            return null;
        }

        try {
            return \Carbon\Carbon::parse($configured, 'UTC');
        } catch (\Throwable $e) {
            Log::warning('TransactionIntakeService: ingestion.timestamps.earliest is unparseable; earliest bound disabled', [
                'exception_class' => $e::class,
            ]);

            return null;
        }
    }

    protected function timestampsEnforced(): bool
    {
        return config('ingestion.timestamps.mode', 'observe') === 'enforce';
    }

    /**
     * One error_code per response: INVALID outranks FUTURE when a batch
     * carries both.
     */
    protected function timestampRejectionCode(array $findings): string
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === self::INVALID_TRANSACTION_TIMESTAMP) {
                return self::INVALID_TRANSACTION_TIMESTAMP;
            }
        }

        return self::FUTURE_TRANSACTION_TIMESTAMP;
    }

    protected function recordWouldRejectTimestamps(array $findings, PosTerminal $terminal, string $traceId): void
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === null) {
                continue;
            }

            Metrics::incr("intake.timestamp_would_reject.{$finding['code']}");
            Log::notice('TransactionIntakeService: transaction timestamp would be rejected (observe mode)', LogContext::ingestion([
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'route' => 'transactions.official',
                'correlation_id' => $traceId,
                'decision' => 'timestamp_would_reject',
                'reason' => $finding['code'],
            ], [
                'transaction_id' => $finding['transaction_id'],
                'code' => $finding['code'],
            ]));
        }
    }

    /**
     * FR-019: valid sales older than the late threshold are accepted
     * unchanged and flagged. Findings carrying a would-reject code (observe
     * mode) are not "valid" and are excluded. Returns extra accept-log
     * context: [] when nothing is late, else late=true and the largest lag.
     */
    protected function recordLateArrivals(array $findings, \Carbon\Carbon $receivedAt): array
    {
        $thresholdSeconds = max(0, (int) config('ingestion.timestamps.late_threshold_minutes', 1440)) * 60;
        $maxLag = null;

        foreach ($findings as $finding) {
            if ($finding['code'] !== null || $finding['normalized'] === null) {
                continue;
            }

            $lag = $receivedAt->getTimestamp() - $finding['normalized']->getTimestamp();
            if ($lag > $thresholdSeconds) {
                Metrics::incr('intake.late_count');
                Metrics::bucket('intake.arrival_lag_seconds', $lag);
                $maxLag = max($maxLag ?? 0, $lag);
            }
        }

        return $maxLag === null ? [] : ['late' => true, 'lag_seconds' => $maxLag];
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
                Metrics::incr("intake.rejected.code.{$errorCode}");
                Metrics::incr('intake.rejected.tenant.'.(auth()->user()->tenant_id ?? 0));
                Metrics::incr('intake.rejected.terminal.'.(auth()->user()->id ?? 0).".{$errorCode}");
            }
        } catch (\Exception $e) {
            Log::warning('TransactionIntakeService: Failed to persist rejection audit', LogContext::ingestion([
                'correlation_id' => $traceId,
                'decision' => 'rejection_persist_failed',
                'reason' => $errorCode,
            ], [
                'error' => $e->getMessage(),
            ]));
        }
    }

    protected function existingIntakeResponse(TransactionIntake $existing, array $payload, string $traceId, mixed $terminal): array
    {
        $data = [
            'submission_uuid' => $existing->submission_uuid,
            'intake_id' => $existing->id,
            'intake_status' => $this->externalIntakeStatus($existing->intake_status),
            'processing_status' => $this->externalProcessingStatus($existing->processing_status, $existing->intake_status),
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

        if ($this->isTerminalProcessedIntake($existing)) {
            return $this->processedIntakeReplayResponse($existing, $payload, $traceId, $data);
        }

        if ($this->isTerminalFailedIntake($existing)) {
            return $this->intakeReplayResponse(
                intake: $existing,
                payload: $payload,
                traceId: $traceId,
                message: 'Submission failed permanently. Correct the payload and resend with a new submission_uuid.',
                success: false,
                status: 'FAILED',
                code: 'FAILED',
                httpStatus: 422,
                itemStatus: 'FAILED',
                itemValidationStatus: 'INVALID',
                itemJobStatus: 'FAILED',
                itemMessage: 'Transaction failed permanently. Correct the payload and resend with a new submission_uuid.',
                checksumValidation: 'matched_stored_checksum',
                additionalData: $data,
                errorCode: $existing->last_error_code ?: 'SUBMISSION_FAILED_PERMANENTLY'
            );
        }

        return $this->acceptedIntakeResponse(
            $existing,
            $payload,
            $traceId,
            'Submission already accepted',
            $data,
            'matched_stored_checksum'
        );
    }

    protected function acceptedIntakeResponse(
        TransactionIntake $intake,
        array $payload,
        string $traceId,
        string $message,
        array $additionalData = [],
        string $checksumValidation = 'passed'
    ): array {
        return $this->intakeReplayResponse(
            intake: $intake,
            payload: $payload,
            traceId: $traceId,
            message: $message,
            success: true,
            status: 'PENDING',
            code: 'ACCEPTED',
            httpStatus: 200,
            itemStatus: 'PENDING',
            itemValidationStatus: 'PENDING',
            itemJobStatus: 'PENDING',
            itemMessage: 'Transaction accepted and pending processing. Poll status after 5 seconds.',
            checksumValidation: $checksumValidation,
            additionalData: $additionalData
        );
    }

    protected function intakeReplayResponse(
        TransactionIntake $intake,
        array $payload,
        string $traceId,
        string $message,
        bool $success,
        string $status,
        string $code,
        int $httpStatus,
        string $itemStatus,
        string $itemValidationStatus,
        string $itemJobStatus,
        string $itemMessage,
        string $checksumValidation,
        array $additionalData = [],
        ?string $errorCode = null,
        ?array $transactionResponses = null
    ): array {
        if ($transactionResponses === null) {
            [$transactions] = $this->submittedTransactions($payload);
            $transactionResponses = array_map(static function (array $transaction) use ($itemStatus, $itemValidationStatus, $itemJobStatus, $itemMessage): array {
                return [
                    'transaction_id' => $transaction['transaction_id'] ?? null,
                    'status' => $itemStatus,
                    'validation_status' => $itemValidationStatus,
                    'job_status' => $itemJobStatus,
                    'message' => $itemMessage,
                ];
            }, array_values(array_filter($transactions, 'is_array')));
        }

        $itemJobStatuses = array_column($transactionResponses, 'job_status');
        $processedCount = count(array_keys($itemJobStatuses, 'COMPLETED', true));
        $failedCount = count(array_keys($itemJobStatuses, 'FAILED', true));
        $pendingCount = count(array_keys($itemJobStatuses, 'PENDING', true));

        $response = [
            'success' => $success,
            'status' => $status,
            'code' => $code,
            'http_status' => $httpStatus,
            'message' => $message,
        ];

        if ($errorCode !== null) {
            $response['error_code'] = $errorCode;
        }

        return $response + [
            'submission_uuid' => $intake->submission_uuid,
            'intake_id' => $intake->id,
            'correlation_id' => $traceId,
            'retryable' => false,
            'data' => array_merge([
                'submission_uuid' => $intake->submission_uuid,
                'intake_id' => $intake->id,
                'processed_count' => $processedCount,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'checksum_validation' => $checksumValidation,
                'transactions' => $transactionResponses,
            ], $additionalData),
        ];
    }

    /**
     * Replay response for an intake whose processing_status is PROCESSED or
     * DUPLICATE. PROCESSED only means the intake job inserted rows and
     * dispatched downstream validation, so each item is reported from its
     * stored outcome instead of assuming VALID/COMPLETED.
     */
    protected function processedIntakeReplayResponse(
        TransactionIntake $intake,
        array $payload,
        string $traceId,
        array $additionalData
    ): array {
        [$transactions] = $this->submittedTransactions($payload);
        $transactions = array_values(array_filter($transactions, 'is_array'));

        if (strtoupper((string) $intake->processing_status) === TransactionIntake::PROCESSING_STATUS_DUPLICATE) {
            $itemMessage = 'Receipt number already used by a recorded transaction; do not resend this receipt under any submission_uuid.';

            return $this->intakeReplayResponse(
                intake: $intake,
                payload: $payload,
                traceId: $traceId,
                message: 'Receipt number already used by a recorded transaction; do not resend this receipt under any submission_uuid.',
                success: false,
                status: 'FAILED',
                code: 'FAILED',
                httpStatus: 422,
                itemStatus: 'FAILED',
                itemValidationStatus: 'INVALID',
                itemJobStatus: 'FAILED',
                itemMessage: $itemMessage,
                checksumValidation: 'matched_stored_checksum',
                additionalData: $additionalData,
                errorCode: 'duplicate_receipt_conflict',
                transactionResponses: array_map(static fn (array $transaction): array => [
                    'transaction_id' => $transaction['transaction_id'] ?? null,
                    'status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'job_status' => 'FAILED',
                    'error_code' => 'duplicate_receipt_conflict',
                    'message' => $itemMessage,
                ], $transactions)
            );
        }

        $isPartialBatchFailure = $intake->last_error_code === 'PARTIAL_BATCH_FAILURE';
        $failedItems = [];

        if ($isPartialBatchFailure) {
            foreach ((array) ($this->decodeStoredErrors($intake->last_error_message)['failed_items'] ?? []) as $failedItem) {
                if (is_array($failedItem) && isset($failedItem['transaction_id'])) {
                    $failedItems[(string) $failedItem['transaction_id']] = $failedItem;
                }
            }
        }

        $transactionIds = array_values(array_filter(array_map(
            static fn (array $transaction) => isset($transaction['transaction_id']) ? (string) $transaction['transaction_id'] : null,
            $transactions
        ), static fn (?string $id) => $id !== null && $id !== ''));

        // Same key as TransactionIngestService's primary dedupe; tenant comes
        // from the stored intake, never from the replayed payload.
        $storedStatuses = $transactionIds === []
            ? []
            : DB::table('transactions')
                ->where('tenant_id', $intake->tenant_id)
                ->whereIn('transaction_id', $transactionIds)
                ->pluck('validation_status', 'transaction_id')
                ->all();

        $receiptConflicts = $this->replayReceiptConflicts(
            $intake,
            array_values(array_filter($transactions, static function (array $transaction) use ($failedItems, $storedStatuses): bool {
                $transactionId = isset($transaction['transaction_id']) ? (string) $transaction['transaction_id'] : null;

                return $transactionId !== null
                    && ! isset($failedItems[$transactionId])
                    && ! array_key_exists($transactionId, $storedStatuses);
            }))
        );

        $transactionResponses = [];

        foreach ($transactions as $transaction) {
            $transactionId = isset($transaction['transaction_id']) ? (string) $transaction['transaction_id'] : null;

            if ($transactionId !== null && isset($failedItems[$transactionId])) {
                $failureMessage = (string) ($failedItems[$transactionId]['message'] ?? 'INGEST_FAILED');
                $transactionResponses[] = [
                    'transaction_id' => $transactionId,
                    'status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'job_status' => 'FAILED',
                    'error_code' => 'PARTIAL_BATCH_FAILURE',
                    'message' => $failureMessage,
                ];

                continue;
            }

            if ($transactionId !== null && isset($receiptConflicts[$transactionId])) {
                $transactionResponses[] = [
                    'transaction_id' => $transactionId,
                    'status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'job_status' => 'FAILED',
                    'error_code' => 'duplicate_receipt_conflict',
                    'message' => 'Receipt number already used by a recorded transaction; do not resend.',
                ];

                continue;
            }

            if ($transactionId === null || ! array_key_exists($transactionId, $storedStatuses)) {
                $transactionResponses[] = [
                    'transaction_id' => $transactionId,
                    'status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'job_status' => 'FAILED',
                    'error_code' => 'TRANSACTION_NOT_RECORDED',
                    'message' => 'Transaction was not recorded for this tenant.',
                ];

                continue;
            }

            $transactionResponses[] = match (strtoupper((string) $storedStatuses[$transactionId])) {
                Transaction::VALIDATION_STATUS_VALID => [
                    'transaction_id' => $transactionId,
                    'status' => 'COMPLETED',
                    'validation_status' => 'VALID',
                    'job_status' => 'COMPLETED',
                    'message' => 'Transaction already processed.',
                ],
                // Pre-existing enum mismatch (out of scope here): transactions.validation_status
                // is ENUM('PENDING','VALID','ERROR','INVALID') but ProcessTransactionJob writes
                // 'FAILED', which non-strict MySQL stores as ''. Treat FAILED/ERROR/INVALID and
                // an empty/NULL status on an existing row as failed, never as pending.
                Transaction::VALIDATION_STATUS_FAILED, 'ERROR', 'INVALID', '' => [
                    'transaction_id' => $transactionId,
                    'status' => 'FAILED',
                    'validation_status' => 'INVALID',
                    'job_status' => 'FAILED',
                    'error_code' => 'TRANSACTION_VALIDATION_FAILED',
                    'message' => 'Transaction failed validation.',
                ],
                default => [
                    'transaction_id' => $transactionId,
                    'status' => 'PENDING',
                    'validation_status' => 'PENDING',
                    'job_status' => 'PENDING',
                    'message' => 'Transaction recorded and pending validation. Poll status after 5 seconds.',
                ],
            };
        }

        $itemStatuses = array_column($transactionResponses, 'status');
        $failedResponses = array_values(array_filter($transactionResponses, static fn (array $item) => $item['status'] === 'FAILED'));

        if ($failedResponses !== []) {
            $failedCodes = array_values(array_unique(array_column($failedResponses, 'error_code')));
            $errorCode = ! $isPartialBatchFailure && count($failedCodes) === 1
                ? $failedCodes[0]
                : 'PARTIAL_BATCH_FAILURE';
            $resendableFailures = array_filter(
                $failedResponses,
                static fn (array $item) => ($item['error_code'] ?? null) !== 'duplicate_receipt_conflict'
            );
            $failureMessage = match (true) {
                $resendableFailures === [] => 'Submission already processed; the failed transactions reuse receipt numbers already used by recorded transactions. Do not resend them under any submission_uuid.',
                count($resendableFailures) < count($failedResponses) => 'Submission already processed with failed transactions. See data.transactions for per-transaction results; correct and resend only the failed transactions whose error_code is not duplicate_receipt_conflict, with a new submission_uuid. Do not resend transactions marked duplicate_receipt_conflict.',
                default => 'Submission already processed with failed transactions. See data.transactions for per-transaction results; correct only the failed transactions and send them with a new submission_uuid.',
            };

            return $this->intakeReplayResponse(
                intake: $intake,
                payload: $payload,
                traceId: $traceId,
                message: $failureMessage,
                success: false,
                status: 'FAILED',
                code: 'PARTIAL_FAILURE',
                httpStatus: 422,
                itemStatus: 'FAILED',
                itemValidationStatus: 'INVALID',
                itemJobStatus: 'FAILED',
                itemMessage: '',
                checksumValidation: 'matched_stored_checksum',
                additionalData: $additionalData,
                errorCode: $errorCode,
                transactionResponses: $transactionResponses
            );
        }

        if (in_array('PENDING', $itemStatuses, true)) {
            return $this->intakeReplayResponse(
                intake: $intake,
                payload: $payload,
                traceId: $traceId,
                message: 'Submission already accepted; transactions are pending validation',
                success: true,
                status: 'PENDING',
                code: 'ACCEPTED',
                httpStatus: 200,
                itemStatus: 'PENDING',
                itemValidationStatus: 'PENDING',
                itemJobStatus: 'PENDING',
                itemMessage: '',
                checksumValidation: 'matched_stored_checksum',
                additionalData: $additionalData,
                transactionResponses: $transactionResponses
            );
        }

        return $this->intakeReplayResponse(
            intake: $intake,
            payload: $payload,
            traceId: $traceId,
            message: 'Submission already processed (idempotent)',
            success: true,
            status: 'COMPLETED',
            code: 'COMPLETED',
            httpStatus: 200,
            itemStatus: 'COMPLETED',
            itemValidationStatus: 'VALID',
            itemJobStatus: 'COMPLETED',
            itemMessage: '',
            checksumValidation: 'matched_stored_checksum',
            additionalData: $additionalData,
            transactionResponses: $transactionResponses
        );
    }

    /**
     * For submitted items with no recorded row, find ones whose receipt_no was
     * already used by a recorded transaction on the same tenant + terminal within
     * ±24h, mirroring TransactionIngestService::findReceiptConflict(). Those were
     * skipped by the intake job as duplicate_receipt_conflict (not failed_items),
     * so they must not be reported as resendable TRANSACTION_NOT_RECORDED.
     *
     * @return array<string, true> keyed by transaction_id
     */
    protected function replayReceiptConflicts(TransactionIntake $intake, array $transactions): array
    {
        $receiptsByTransaction = [];

        foreach ($transactions as $transaction) {
            $receiptNo = trim((string) ($transaction['receipt_no'] ?? ''));

            if ($receiptNo !== '' && isset($transaction['transaction_id'])) {
                $receiptsByTransaction[(string) $transaction['transaction_id']] = [$receiptNo, $transaction];
            }
        }

        if ($receiptsByTransaction === []) {
            return [];
        }

        $receiptNos = array_values(array_unique(array_column($receiptsByTransaction, 0)));
        $rows = DB::table('transactions')
            ->where('tenant_id', $intake->tenant_id)
            ->where('terminal_id', $intake->terminal_id)
            ->whereIn(DB::raw('TRIM(receipt_no)'), $receiptNos)
            ->get(['receipt_no', 'transaction_timestamp']);

        $conflicts = [];

        foreach ($receiptsByTransaction as $transactionId => [$receiptNo, $transaction]) {
            $incomingTs = strtotime($this->replayNormalizedTimestamp($intake, (string) ($transaction['transaction_timestamp'] ?? '')));

            if ($incomingTs === false) {
                continue;
            }

            foreach ($rows as $row) {
                $existingTs = strtotime((string) $row->transaction_timestamp);

                if (trim((string) $row->receipt_no) === $receiptNo
                    && $existingTs !== false
                    && abs($existingTs - $incomingTs) <= 86400) {
                    $conflicts[$transactionId] = true;

                    break;
                }
            }
        }

        return $conflicts;
    }

    /**
     * Normalize like TransactionIngestService::normalizePayload() so the ±24h
     * window compares against the same stored representation; falls back to
     * the raw submitted value if normalization is not possible.
     */
    private function replayNormalizedTimestamp(TransactionIntake $intake, string $timestamp): string
    {
        if ($timestamp === '') {
            return '';
        }

        try {
            return app(ProviderTimestampNormalizer::class)->normalize($timestamp, (int) $intake->terminal_id);
        } catch (\Throwable) {
            return $timestamp;
        }
    }

    private function isTerminalProcessedIntake(TransactionIntake $intake): bool
    {
        return in_array(strtoupper((string) $intake->processing_status), [
            TransactionIntake::PROCESSING_STATUS_PROCESSED,
            TransactionIntake::PROCESSING_STATUS_DUPLICATE,
        ], true);
    }

    private function isTerminalFailedIntake(TransactionIntake $intake): bool
    {
        return in_array(strtoupper((string) $intake->processing_status), [
            TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
            TransactionIntake::PROCESSING_STATUS_DEAD_LETTERED,
        ], true);
    }

    private function externalIntakeStatus(?string $intakeStatus): ?string
    {
        return match (strtoupper((string) $intakeStatus)) {
            TransactionIntake::INTAKE_STATUS_REJECTED => 'REJECTED',
            TransactionIntake::INTAKE_STATUS_RECEIVED,
            TransactionIntake::INTAKE_STATUS_ACCEPTED,
            TransactionIntake::INTAKE_STATUS_QUEUED => 'PENDING',
            '' => null,
            default => strtoupper((string) $intakeStatus),
        };
    }

    private function externalProcessingStatus(?string $processingStatus, ?string $intakeStatus = null): ?string
    {
        return match (strtoupper((string) $processingStatus)) {
            TransactionIntake::PROCESSING_STATUS_PROCESSING => 'PROCESSING',
            TransactionIntake::PROCESSING_STATUS_PROCESSED,
            TransactionIntake::PROCESSING_STATUS_DUPLICATE => 'COMPLETED',
            TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
            TransactionIntake::PROCESSING_STATUS_DEAD_LETTERED => 'FAILED',
            TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
            'PENDING' => 'PENDING',
            '' => $this->externalIntakeStatus($intakeStatus),
            default => strtoupper((string) $processingStatus),
        };
    }

    protected function existingLegacySubmissionResponse(TransactionSubmission $existing, array $payload, string $traceId, mixed $terminal): array
    {
        $sameTerminal = $terminal instanceof PosTerminal && (int) $existing->terminal_id === (int) $terminal->id;
        $samePayload = hash_equals((string) $existing->payload_checksum, (string) ($payload['payload_checksum'] ?? ''))
            && (int) $existing->transaction_count === (int) ($payload['transaction_count'] ?? 0);

        if (! $sameTerminal || ! $samePayload) {
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
