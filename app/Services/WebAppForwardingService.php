<?php
namespace App\Services;

use App\Services\PayloadChecksumService;

use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use App\Support\LogContext;
use App\Support\Metrics;
use App\Support\RejectionPlaybook;
use App\Support\TenantBreakerObserver;

class WebAppForwardingService
{
    /**
     * Failure classification constants for logging & circuit breaker decisions.
     */
    private const CLASS_HTTP_422_VALIDATION   = 'HTTP_422_VALIDATION';
    private const CLASS_HTTP_4XX              = 'HTTP_4XX';
    private const CLASS_HTTP_5XX_RETRYABLE    = 'HTTP_5XX_RETRYABLE';
    private const CLASS_NETWORK_DNS           = 'NETWORK_DNS';
    private const CLASS_NETWORK_OTHER         = 'NETWORK_OTHER';
    private const CLASS_LOCAL_VALIDATION_FAIL = 'LOCAL_VALIDATION_FAILED';
    private const CLASS_LOCAL_BATCH_CONTRACT_FAIL = 'LOCAL_BATCH_CONTRACT_FAILED';

    /**
     * Outbound payload schema version for bulk (multi-transaction) forwarding envelopes.
     * Increment when making backward-incompatible changes to the bulk envelope structure.
     */
    private const BULK_SCHEMA_VERSION = '2.0';

    /**
     * Centralized structured logging helper to enforce consistent context ("stamp").
     * Accepts optional Transaction or WebappTransactionForward to enrich context automatically.
     */
    private function log(string $level, string $message, array $context = [], ?\App\Models\Transaction $transaction = null, ?WebappTransactionForward $forward = null): void
    {
        $base = [];
        if ($forward) {
            $base = \App\Support\LogContext::fromForward($forward);
        } elseif ($transaction) {
            $base = \App\Support\LogContext::fromTransaction($transaction);
        } else {
            $base = \App\Support\LogContext::base();
        }
        // Always include schema_version so triage knows envelope generation variant
        $merged = array_merge($base, ['schema_version' => self::BULK_SCHEMA_VERSION], $context);
        try {
            \Log::$level($message, $merged);
        } catch (\Throwable $e) {
            // Fallback (should not happen) – ensure we never break primary flow due to logging
            \Log::error('Structured log failure', [
                'original_message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process automated void transaction forwarding.
     *
     * @param string $transactionId
     * @param string|null $reason
     * @return void
     */
    public function processVoidTransaction($transactionId, $reason = null)
    {
        $transaction = \App\Models\Transaction::where('transaction_id', $transactionId)->first();
        if ($transaction && !$transaction->isVoided()) {
            $transaction->void($reason);
            $this->forwardVoidedTransaction($transaction);
        }
    }

    /**
     * Forward voided transaction to webapp.
     *
     * @param \App\Models\Transaction $transaction
     * @return void
     */
    public function forwardVoidedTransaction($transaction)
    {
        $payload = [
            'tsms_id'               => $transaction->id,
            'transaction_id'        => $transaction->transaction_id,
            'terminal_serial'       => $transaction->terminal?->serial_number,
            'tenant_code'           => $transaction->tenant?->customer_code,
            'tenant_name'           => $transaction->tenant?->name,
            'transaction_timestamp' => $this->isoTimestamp($transaction->transaction_timestamp),
            'amount'                => (float) $transaction->gross_sales,
            'net_amount'           => (float) $transaction->net_sales,
            'validation_status'     => $transaction->validation_status,
            'processed_at'          => $this->isoTimestamp($transaction->created_at),
            'submission_uuid'       => $transaction->submission_uuid,
            'voided_at'             => $this->isoTimestamp($transaction->voided_at),
            'void_reason'           => $transaction->void_reason,
            'status'                => 'VOIDED',
        ];
        $payload['checksum'] = $this->checksumService->computeChecksum($payload);

        $client = \Illuminate\Support\Facades\Http::timeout($this->timeout)
            ->withToken($this->authToken);
        if (! $this->verifySSL) {
            $client = $client->withoutVerifying();
        }
        try {
            $response = $client->post($this->webAppEndpoint, $payload);
            \Illuminate\Support\Facades\Log::info('Forwarded voided transaction', [
                'transaction_id' => $transaction->transaction_id,
                'response_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to forward voided transaction', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    /**
     * Notify POS of permanent forwarding failure
     */
    private function notifyPosFailure(WebappTransactionForward $forward)
    {
        $callbackUrl = $forward->transaction->terminal->callback_url ?? null;
        if (!config('notifications.callbacks.enabled')) {
            \Log::info('POS failure notification suppressed (global callbacks disabled)', [
                'transaction_id' => $forward->transaction_id,
            ]);
            return;
        }
        if (!$callbackUrl) {
            \Log::warning('No POS callback URL for failed transaction', ['transaction_id' => $forward->transaction_id]);
            return;
        }
        $payload = [
            'transaction_id' => $forward->transaction->transaction_id ?? $forward->transaction_id,
            'status' => 'FAILED',
            'error' => $forward->error_message ?? 'Forwarding failed after max attempts',
        ];
        try {
            $response = \Http::post($callbackUrl, $payload);
            \Log::info('POS notified of permanent failure', [
                'transaction_id' => $forward->transaction_id,
                'callback_url' => $callbackUrl,
                'response_status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Failed to notify POS of permanent failure', [
                'transaction_id' => $forward->transaction_id,
                'callback_url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
    private PayloadChecksumService $checksumService;
    private string $webAppEndpoint;
    private int    $timeout;
    private int    $batchSize;
    private string $authToken;
    private bool   $verifySSL;
    private bool   $circuitBreakerEnabled;
    private int    $circuitBreakerThreshold;
    private int    $circuitBreakerCooldown;
    private string $circuitBreakerKey = 'webapp_forwarding_circuit_breaker';
    private TenantBreakerObserver $tenantObserver; // Phase 1 observational per-tenant metrics

    public function __construct()
    {
        $this->webAppEndpoint           = config('tsms.web_app.endpoint', '');
        $this->timeout                  = config('tsms.web_app.timeout', 30);
        $this->batchSize                = config('tsms.web_app.batch_size', 50);
        $this->authToken                = config('tsms.web_app.auth_token', '');
        $this->verifySSL                = config('tsms.web_app.verify_ssl', false);
        // Updated to leverage new config naming (fallback to legacy keys if present)
        $this->circuitBreakerEnabled    = config('tsms.circuit_breaker.enabled', true);
        $this->circuitBreakerThreshold  = config('tsms.circuit_breaker.failure_threshold', config('tsms.circuit_breaker.threshold', 5));
        $this->circuitBreakerCooldown   = config('tsms.circuit_breaker.recovery_timeout_minutes', config('tsms.circuit_breaker.cooldown', 10));
        $this->checksumService          = app(PayloadChecksumService::class);
    $this->tenantObserver           = app(TenantBreakerObserver::class);

        // Capture-only production safety guard
        if (app()->environment('production') && config('tsms.testing.capture_only') && !config('tsms.testing.allow_capture_only_in_production')) {
            \Log::critical('capture_only mode was ON in production – forcibly disabling', [
                'schema_version' => self::BULK_SCHEMA_VERSION,
            ]);
            config(['tsms.testing.capture_only' => false]);
        }
    }

    public function forwardUnsentTransactions(): array
    {
        return $this->processUnforwardedTransactions();
    }

    public function processUnforwardedTransactions(): array
    {
        $this->assertEndpoint();

        if ($this->circuitBreakerEnabled && $this->isCircuitBreakerOpen()) {
            $this->log('warning', 'WebApp forwarding skipped – circuit breaker OPEN');
            return ['success' => false, 'reason' => 'circuit_breaker_open'];
        }

        $records = $this->getTransactionsForForwarding();
        if ($records->isEmpty()) {
            return ['success' => true, 'forwarded_count' => 0, 'reason' => 'no_transactions'];
        }

        // Get submission_uuid from first transaction (assumes batch is for one submission)
        $submissionUuid = $records->first()->submission_uuid ?? null;
        if ($submissionUuid) {
            $existing = \App\Models\WebappTransactionForward::where('submission_uuid', $submissionUuid)
                ->where('status', WebappTransactionForward::STATUS_COMPLETED)
                ->get();
            if ($existing->count() > 0) {
                $this->log('info', 'Idempotent forwarding: submission already processed', [
                    'submission_uuid' => $submissionUuid,
                ]);
                return [
                    'success' => true,
                    'forwarded_count' => $existing->count(),
                    'batch_id' => $existing->first()->batch_id,
                    'idempotent' => true
                ];
            }
        }

        $forwarding = $this->createForwardingRecords($records);

        // Phase 1: record attempt for tenant (observation only)
        $tenantId = $forwarding->first()?->transaction?->tenant_id;
        $this->tenantObserver->recordAttempt($tenantId);

        return $this->processBatchForwarding($forwarding, $tenantId);
    }

    private function assertEndpoint(): void
    {
        if (empty($this->webAppEndpoint)) {
            throw new \InvalidArgumentException('WebApp endpoint not configured.');
        }
    }

    private function getTransactionsForForwarding(): Collection
    {
        $candidates = Transaction::query()
            ->where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', fn ($q) =>
                $q->where('status', WebappTransactionForward::STATUS_COMPLETED)
            )
            ->with(['terminal', 'tenant', 'jobs', 'adjustments', 'taxes'])
            ->orderBy('created_at', 'asc')
            ->limit($this->batchSize * 2)
            ->get();

        return $candidates
            ->filter(fn (Transaction $tx) => $tx->latest_job_status === Transaction::JOB_STATUS_COMPLETED)
            ->take($this->batchSize)
            ->values();
    }

    private function createForwardingRecords(Collection $transactions): Collection
    {
        $batchId = 'TSMS_' . now()->format('YmdHis') . '_' . uniqid();

        return $transactions->map(function (Transaction $tx) use ($batchId) {
            // Always recompute checksum before building payload
            $adjustments = $tx->adjustments->map(function ($adj) {
                return [
                    'adjustment_type' => $adj->adjustment_type,
                    'amount' => (float) $adj->amount,
                ];
            })->toArray();

            // Ensure all adjustment types are included (even if amount is 0)
            $requiredAdjustmentTypes = [
                'promo_discount',
                'senior_discount',
                'pwd_discount',
                'vip_card_discount',
                'service_charge_distributed_to_employees',
                'service_charge_retained_by_management',
                'employee_discount'
            ];

            $completeAdjustments = [];
            foreach ($requiredAdjustmentTypes as $type) {
                $existing = collect($adjustments)->firstWhere('adjustment_type', $type);
                $completeAdjustments[] = $existing ?: [
                    'adjustment_type' => $type,
                    'amount' => 0.00
                ];
            }

            $taxes = $tx->taxes->map(function ($tax) {
                return [
                    'tax_type' => $tax->tax_type,
                    'amount' => (float) $tax->amount,
                ];
            })->toArray();

            // Ensure all tax types are included
            $requiredTaxTypes = [
                'VAT',
                'VATABLE_SALES',
                'SC_VAT_EXEMPT_SALES',
                'OTHER_TAX'
            ];

            $completeTaxes = [];
            foreach ($requiredTaxTypes as $type) {
                $existing = collect($taxes)->firstWhere('tax_type', $type);
                $completeTaxes[] = $existing ?: [
                    'tax_type' => $type,
                    'amount' => 0.00
                ];
            }

            $payloadArr = [
                'tsms_id' => $tx->id,
                'transaction_id' => (string)$tx->transaction_id,
                'terminal_serial' => $this->s($tx->terminal?->serial_number),
                'tenant_code' => $this->s($tx->tenant?->customer_code),
                'tenant_name' => $this->s($tx->tenant?->name),
                'transaction_timestamp' => $this->isoTimestamp($tx->transaction_timestamp),
                'amount' => (float) $tx->gross_sales,
                'net_amount' => (float) $tx->net_sales,
                'validation_status' => $this->s($tx->validation_status),
                'processed_at' => $this->isoTimestamp($tx->created_at),
                'submission_uuid' => $this->s($tx->submission_uuid),
                'adjustments' => $this->normalizeAdjustments($completeAdjustments),
                'taxes' => $this->normalizeTaxes($completeTaxes),
            ];
            // Remove checksum if present, then compute
            unset($payloadArr['checksum']);
            $payloadArr['checksum'] = $this->checksumService->computeChecksum($payloadArr);

            $forward = WebappTransactionForward::updateOrCreate(
                [
                    'transaction_id' => $tx->id,
                ],
                [
                    'batch_id'        => $batchId,
                    'status'          => WebappTransactionForward::STATUS_PENDING,
                    'max_attempts'    => 3,
                    'request_payload' => $payloadArr,
                    'submission_uuid' => $tx->submission_uuid,
                ]
            );
            return $forward->load('transaction.terminal', 'transaction.tenant');
        });
    }

    /**
     * Processes a batch of records for forwarding to the web application endpoint.
     *
     * This method marks all records as "in progress", builds the bulk payload, and sends it via HTTP POST
     * to the configured web application endpoint. It handles SSL verification based on configuration,
     * logs the payload and results, and manages circuit breaker state.
     *
     * On success, marks all records as completed and returns a success response with batch details.
     * On failure (HTTP or other exceptions), logs the error, records the failure, handles batch failure,
     * and returns an error response with batch details.
     *
     * @param \Illuminate\Support\Collection $records The collection of records to be forwarded.
     * @return array An associative array containing the result of the forwarding operation:
     *               - 'success' (bool): Whether the forwarding was successful.
     *               - 'forwarded_count' (int, optional): Number of records forwarded (on success).
     *               - 'error' (string, optional): Error message (on failure).
     *               - 'batch_id' (mixed): The batch ID associated with the records.
     */
    private function processBatchForwarding(Collection $records, ?int $tenantId = null): array
    {
        $batchId = $records->first()->batch_id;
        $records->each(fn ($f) => $f->markAsInProgress());

        // Fast feature flag gate: allow immediate safe disable of forwarding without code deploy
        if (!config('tsms.web_app.enabled', true)) {
            $this->log('warning', 'WebApp forwarding globally disabled via config flag');
            // Mark as failed with explicit status but do NOT increment breaker
            $this->handleBatchFailure($records, 'Forwarding disabled by feature flag', 0);
            return [
                'success' => false,
                'error' => 'forwarding_disabled',
                'batch_id' => $batchId,
                'classification' => self::CLASS_LOCAL_VALIDATION_FAIL,
            ];
        }

        try {
            $payload = $this->buildBulkPayload($records, $batchId);
        } catch (\RuntimeException $e) {
            // Likely homogeneity violation (mixed tenant / terminal) or similar batch contract issue
            $msg = $e->getMessage();
            $classification = self::CLASS_LOCAL_BATCH_CONTRACT_FAIL;
            $this->log('error', 'Batch contract failure during payload build', [
                'batch_id' => $batchId,
                'error' => $msg,
                'classification' => $classification,
                'remediation' => RejectionPlaybook::explain($msg),
            ]);
            // Mark records failed (do not increment breaker)
            $this->handleBatchFailure($records, $msg, 0);
            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId,
                'classification' => $classification,
            ];
        }

        // Local outbound contract validation (fail fast before HTTP)
        $validation = $this->validateBulkPayload($payload);
        if (!$validation['valid']) {
            $errorMsg = 'Outbound payload invalid';
            $errors = $validation['errors'];
            $missingIds = [];
            if (isset($errors['tenant_id'])) { $missingIds[] = 'tenant_id'; }
            if (isset($errors['terminal_id'])) { $missingIds[] = 'terminal_id'; }
            $this->log('error', 'Outbound payload validation failed', [
                'batch_id' => $batchId,
                'errors' => $errors,
                'missing_ids' => $missingIds,
                'missing_id_metric' => !empty($missingIds),
                'classification' => self::CLASS_LOCAL_VALIDATION_FAIL,
                'remediation' => RejectionPlaybook::explain($errorMsg),
            ]);
            $this->handleBatchFailure($records, $errorMsg . ': ' . json_encode($validation['errors']), 0);
            // Do NOT increment circuit breaker for local validation issues
            return [
                'success' => false,
                'error' => $errorMsg,
                'batch_id' => $batchId,
                'classification' => self::CLASS_LOCAL_VALIDATION_FAIL,
            ];
        }

        // Capture-only (test) mode for batch path
        if (config('tsms.testing.capture_only')) {
            $records->each(fn ($f) => $f->markAsCompleted(['captured' => true], 200));
            Metrics::incr('forwarding.captured', $records->count());
            return [
                'success' => true,
                'forwarded_count' => $records->count(),
                'batch_id' => $batchId,
                'classification' => 'SUCCESS',
                'captured_payload' => $payload,
            ];
        }

        try {
            $client = Http::timeout($this->timeout)
                          ->withToken($this->authToken);

            if (! $this->verifySSL) {
                $client = $client->withoutVerifying();
            }

            $this->log('debug', '[TSMS] Forwarding payload', $payload);
            $response = $client->post($this->webAppEndpoint, $payload)->throw();

            // Check if response is JSON or HTML (protection redirect)
            $contentType = $response->header('Content-Type');
            if (str_contains($contentType, 'application/json')) {
                $data = $response->json();
            } elseif (str_contains($contentType, 'text/html')) {
                // Handle protection redirect - treat as successful connection but log the issue
                Log::warning('WebApp returned HTML redirect instead of JSON - likely protection service', [
                    'endpoint' => $this->webAppEndpoint,
                    'status' => $response->status(),
                    'content_type' => $contentType
                ]);
                $data = ['protection_redirect' => true, 'status' => $response->status()];
            } else {
                $data = ['unknown_response_type' => $contentType, 'status' => $response->status()];
            }

            $records->each(fn ($f) => $f->markAsCompleted($data, $response->status()));
            $this->resetCircuitBreaker();

            // Evaluate tenant breaker observation window post-success (attempt accounted earlier)
            if ($tenantId) {
                $eval = $this->tenantObserver->evaluate($tenantId);
                if ($eval && ($eval['eligible'] ?? false) && ($eval['over_threshold'] ?? false)) {
                    $this->log('warning', 'Tenant breaker observation threshold crossed (success path)', [
                        'tenant_id' => $tenantId,
                        'attempts' => $eval['attempts'],
                        'failures' => $eval['failures'],
                        'failure_ratio' => $eval['failure_ratio'],
                        'threshold_ratio' => $eval['failure_ratio_threshold'],
                        'window_minutes' => $eval['window_minutes'],
                        'phase' => 'observation',
                        'enforced' => false,
                    ]);
                }
            }

            Metrics::incr('forwarding.success', $records->count());
            $this->log('info', 'Bulk forwarded', [
                'batch_id' => $batchId,
                'count' => $records->count(),
                'classification' => 'SUCCESS',
            ]);
            return [
                'success' => true,
                'forwarded_count' => $records->count(),
                'batch_id' => $batchId,
                'classification' => 'SUCCESS'
            ];

        } catch (RequestException $e) {
            $msg = $e->getMessage();
            $status = $e->response?->status();
            $classification = $this->classifyHttpFailure($status, $msg);
            Metrics::incr('forwarding.failure');
            if (in_array($classification, [self::CLASS_HTTP_5XX_RETRYABLE, self::CLASS_NETWORK_DNS, self::CLASS_NETWORK_OTHER], true)) {
                $this->tenantObserver->recordRetryableFailure($tenantId ?? ($records->first()?->transaction?->tenant_id));
            }
            $this->log('error', 'HTTP forwarding error', [
                'error' => $msg,
                'status' => $status,
                'classification' => $classification,
                'batch_id' => $batchId,
            ]);
            $this->maybeRecordBreakerFailure($classification);
            $this->handleBatchFailure($records, $msg, $status);
            if ($tenantId) {
                $eval = $this->tenantObserver->evaluate($tenantId);
                if ($eval && ($eval['eligible'] ?? false) && ($eval['over_threshold'] ?? false)) {
                    $this->log('warning', 'Tenant breaker observation threshold crossed (failure path)', [
                        'tenant_id' => $tenantId,
                        'attempts' => $eval['attempts'],
                        'failures' => $eval['failures'],
                        'failure_ratio' => $eval['failure_ratio'],
                        'threshold_ratio' => $eval['failure_ratio_threshold'],
                        'window_minutes' => $eval['window_minutes'],
                        'phase' => 'observation',
                        'enforced' => false,
                    ]);
                }
            }
            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId,
                'classification' => $classification,
            ];

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $classification = $this->classifyThrowable($msg);
            Metrics::incr('forwarding.failure');
            if (in_array($classification, [self::CLASS_HTTP_5XX_RETRYABLE, self::CLASS_NETWORK_DNS, self::CLASS_NETWORK_OTHER], true)) {
                $this->tenantObserver->recordRetryableFailure($tenantId ?? ($records->first()?->transaction?->tenant_id));
            }
            $this->log('error', 'Forwarding exception', [
                'error' => $msg,
                'classification' => $classification,
                'batch_id' => $batchId,
            ]);
            $this->maybeRecordBreakerFailure($classification);
            $this->handleBatchFailure($records, $msg);
            if ($tenantId) {
                $eval = $this->tenantObserver->evaluate($tenantId);
                if ($eval && ($eval['eligible'] ?? false) && ($eval['over_threshold'] ?? false)) {
                    $this->log('warning', 'Tenant breaker observation threshold crossed (exception path)', [
                        'tenant_id' => $tenantId,
                        'attempts' => $eval['attempts'],
                        'failures' => $eval['failures'],
                        'failure_ratio' => $eval['failure_ratio'],
                        'threshold_ratio' => $eval['failure_ratio_threshold'],
                        'window_minutes' => $eval['window_minutes'],
                        'phase' => 'observation',
                        'enforced' => false,
                    ]);
                }
            }
            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId,
                'classification' => $classification,
            ];
        }
    }

    private function handleBatchFailure(Collection $records, string $error, ?int $statusCode = null): void
    {
        $records->each(function ($f) use ($error, $statusCode) {
            $f->markAsFailed($error, $statusCode);
            // Notify POS if permanently failed
            if ($f->status === \App\Models\WebappTransactionForward::STATUS_FAILED && $f->attempts >= $f->max_attempts) {
                $this->notifyPosFailure($f);
            }
        });
    }

    // buildTransactionPayload is now inlined in createForwardingRecords to ensure checksum is always up-to-date

    private function buildBulkPayload(Collection $records, string $batchId): array
    {
        // Unified envelope for single or multiple transactions (schema v2.0)
        $first = $records->first();
        $tenantId = $first->transaction->tenant_id ?? null;
        $terminalId = $first->transaction->terminal_id ?? null;

        // Basic homogeneity assertion (industry best practice: a batch should represent a single tenant & terminal)
        $mismatch = $records->contains(function ($r) use ($tenantId, $terminalId) {
            return ($r->transaction->tenant_id ?? null) !== $tenantId || ($r->transaction->terminal_id ?? null) !== $terminalId;
        });
        if ($mismatch) {
            $this->log('warning', 'Bulk payload homogeneity violation: mixed tenant_id / terminal_id detected', [
                'batch_id' => $batchId,
            ]);
            throw new \RuntimeException('Mixed tenant / terminal batch not supported');
        }

        $transactions = $records->pluck('request_payload')->all();
        $transactionChecksums = array_map(fn ($t) => $t['checksum'] ?? '', $transactions);
        $batchChecksum = $this->computeBatchChecksum(
            self::BULK_SCHEMA_VERSION,
            'TSMS',
            $batchId,
            (string)$tenantId,
            (string)$terminalId,
            $records->count(),
            $transactionChecksums
        );
        $envelope = [
            'source' => 'TSMS',
            'schema_version' => self::BULK_SCHEMA_VERSION,
            'batch_id' => $batchId,
            'timestamp' => Carbon::now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'transaction_count' => $records->count(),
            'batch_checksum' => $batchChecksum,
            'transactions' => $transactions,
        ];
        // If capture-only mode active for tests on batch path, attach envelope directly for assertions
        if (config('tsms.testing.capture_only')) {
            // Persist envelope snapshot JSON on each record for debugging / test introspection
            $records->each(function($f) use ($envelope) {
                $f->update(['response_data' => ['captured' => true, 'envelope' => $envelope]]);
            });
        }
        return $envelope;
    }

    private function isoTimestamp(?Carbon $dt): ?string
    {
        return $dt?->format('Y-m-d\\TH:i:s.v\\Z');
    }

    private function isCircuitBreakerOpen(): bool
    {
        $fails    = Cache::get($this->circuitBreakerKey.'_failures', 0);
        $lastFail = Cache::get($this->circuitBreakerKey.'_last_failure');

        if ($fails >= $this->circuitBreakerThreshold) {
            if ($lastFail && now()->diffInMinutes($lastFail) >= $this->circuitBreakerCooldown) {
                $this->resetCircuitBreaker();
                return false;
            }
            return true;
        }

        return false;
    }

    private function recordFailure(): void
    {
        Cache::increment($this->circuitBreakerKey.'_failures');
        Cache::put($this->circuitBreakerKey.'_last_failure', now(), now()->addHours(1));
    }

    /**
     * Decide if a classification should increment breaker.
     */
    private function maybeRecordBreakerFailure(string $classification): void
    {
        // Only retryable failures impact breaker.
        if (in_array($classification, [
            self::CLASS_HTTP_5XX_RETRYABLE,
            self::CLASS_NETWORK_DNS,
            self::CLASS_NETWORK_OTHER,
        ], true)) {
            $this->recordFailure();
        }
    }

    private function classifyHttpFailure(?int $status, string $message): string
    {
        if ($status === 422) {
            return self::CLASS_HTTP_422_VALIDATION; // non-retryable contract mismatch
        }
        if ($status && $status >= 500) {
            return self::CLASS_HTTP_5XX_RETRYABLE;
        }
        if ($status && $status >= 400) {
            return self::CLASS_HTTP_4XX; // treat as non-retryable unless policy says otherwise
        }
        if (str_contains(strtolower($message), 'could not resolve host')) {
            return self::CLASS_NETWORK_DNS;
        }
        return self::CLASS_NETWORK_OTHER;
    }

    private function classifyThrowable(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'could not resolve host')) {
            return self::CLASS_NETWORK_DNS;
        }
        return self::CLASS_NETWORK_OTHER;
    }

    /** String normalization: never return null; trim whitespace. */
    private function s($v): string { return is_string($v) ? trim($v) : ''; }
    /** Float normalization */
    private function f($v): float { return (float)($v ?? 0); }
    /** Normalize adjustments array */
    private function normalizeAdjustments(array $arr): array
    {
        return array_map(fn($a) => [
            'adjustment_type' => $this->s($a['adjustment_type'] ?? null),
            'amount' => $this->f($a['amount'] ?? 0),
        ], $arr);
    }
    /** Normalize taxes array */
    private function normalizeTaxes(array $arr): array
    {
        return array_map(fn($t) => [
            'tax_type' => $this->s($t['tax_type'] ?? null),
            'amount' => $this->f($t['amount'] ?? 0),
        ], $arr);
    }

    /** Validate outbound bulk payload shape */
    private function validateBulkPayload(array $payload): array
    {
        // If legacy single-transaction structure, skip (handled elsewhere)
        if (!isset($payload['transactions'])) {
            return ['valid' => true];
        }
        $rules = [
            'source' => ['required','string'],
            'schema_version' => ['required','in:2.0'],
            'batch_id' => ['required','string'],
            'timestamp' => ['required','date_format:Y-m-d\\TH:i:s.v\\Z'],
            'transaction_count' => ['required','integer','gte:1'],
            'tenant_id' => ['required','integer','gte:1'],
            'terminal_id' => ['required','integer','gte:1'],
            'batch_checksum' => ['required','string','size:64'],
            'transactions' => ['required','array','min:1'],
            'transactions.*.transaction_id' => ['required','string'],
            // Allow empty strings for terminal_serial / tenant_code in test contexts; still require key presence
            'transactions.*.terminal_serial' => ['required','string'],
            'transactions.*.tenant_code' => ['required','string'],
            'transactions.*.tenant_name' => ['string'], // optional but must be string
            'transactions.*.transaction_timestamp' => ['required','date_format:Y-m-d\\TH:i:s.v\\Z'],
            'transactions.*.amount' => ['required','numeric','gte:0'],
            'transactions.*.net_amount' => ['required','numeric','gte:0'],
            'transactions.*.adjustments' => ['array'],
            'transactions.*.adjustments.*.adjustment_type' => ['required','string'],
            'transactions.*.adjustments.*.amount' => ['required','numeric'],
            'transactions.*.taxes' => ['array'],
            'transactions.*.taxes.*.tax_type' => ['required','string'],
            'transactions.*.taxes.*.amount' => ['required','numeric'],
            'transactions.*.checksum' => ['required','string','size:64'],
        ];
        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            return ['valid' => false, 'errors' => $validator->errors()->toArray()];
        }
        return ['valid' => true];
    }

    /**
     * Compute deterministic batch checksum from envelope identifying fields and per-transaction checksums.
     * @param string $batchId
     * @param string $tenantId
     * @param string $terminalId
     * @param array $transactionChecksums
     * @return string 64-char hex SHA-256
     */
    private function computeBatchChecksum(string $schemaVersion, string $source, string $batchId, string $tenantId, string $terminalId, int $transactionCount, array $transactionChecksums): string
    {
        // Stable ordering: sort checksums ascending to avoid order-based variation, then concatenate with key envelope fields.
        $filtered = array_filter($transactionChecksums, fn ($c) => is_string($c) && $c !== '');
        sort($filtered, SORT_STRING);
        $concat = implode('|', [
            $schemaVersion,
            $source,
            $batchId,
            $tenantId,
            $terminalId,
            $transactionCount,
            implode(',', $filtered)
        ]);
        return hash('sha256', $concat);
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget($this->circuitBreakerKey.'_failures');
        Cache::forget($this->circuitBreakerKey.'_last_failure');
    }

    public function getForwardingStats(): array
    {
        return [
            'unforwarded_transactions' => Transaction::where('validation_status', 'VALID')
                ->whereDoesntHave('webappForward', fn($q) =>
                    $q->where('status', WebappTransactionForward::STATUS_COMPLETED)
                )
                ->count(),
            'pending_forwards'   => WebappTransactionForward::pending()->count(),
            'completed_forwards' => WebappTransactionForward::completed()->count(),
            'failed_forwards'    => WebappTransactionForward::failed()->count(),
            'circuit_breaker'    => [
                'is_open'      => $this->isCircuitBreakerOpen(),
                'failures'     => Cache::get($this->circuitBreakerKey.'_failures', 0),
                'last_failure' => Cache::get($this->circuitBreakerKey.'_last_failure'),
            ],
        ];
    }

    /**
     * Forward a specific transaction immediately (real-time forwarding)
     *
     * @param Transaction $transaction
     * @return array
     */
    public function forwardTransactionImmediately(Transaction $transaction): array
    {
        $this->assertEndpoint();

        // Load relationships if not already loaded
        if (!$transaction->relationLoaded('adjustments')) {
            $transaction->load('adjustments');
        }
        if (!$transaction->relationLoaded('taxes')) {
            $transaction->load('taxes');
        }

        // Check if transaction is already forwarded
        $existing = WebappTransactionForward::where('transaction_id', $transaction->id)
            ->where('status', WebappTransactionForward::STATUS_COMPLETED)
            ->first();

        if ($existing) {
            return [
                'success' => true,
                'message' => 'Transaction already forwarded',
                'forward_id' => $existing->id
            ];
        }

        // Build payload for single transaction using user's specified structure
        $adjustments = $transaction->adjustments->map(function ($adj) {
            return [
                'adjustment_type' => $adj->adjustment_type,
                'amount' => (float) $adj->amount,
            ];
        })->toArray();

        // Ensure all adjustment types are included (even if amount is 0)
        $requiredAdjustmentTypes = [
            'promo_discount',
            'senior_discount',
            'pwd_discount',
            'vip_card_discount',
            'service_charge_distributed_to_employees',
            'service_charge_retained_by_management',
            'employee_discount'
        ];

        $completeAdjustments = [];
        foreach ($requiredAdjustmentTypes as $type) {
            $existing = collect($adjustments)->firstWhere('adjustment_type', $type);
            $completeAdjustments[] = $existing ?: [
                'adjustment_type' => $type,
                'amount' => 0.00
            ];
        }

        $taxes = $transaction->taxes->map(function ($tax) {
            return [
                'tax_type' => $tax->tax_type,
                'amount' => (float) $tax->amount,
            ];
        })->toArray();

        // Ensure all tax types are included
        $requiredTaxTypes = [
            'VAT',
            'VATABLE_SALES',
            'SC_VAT_EXEMPT_SALES',
            'OTHER_TAX'
        ];

        $completeTaxes = [];
        foreach ($requiredTaxTypes as $type) {
            $existing = collect($taxes)->firstWhere('tax_type', $type);
            $completeTaxes[] = $existing ?: [
                'tax_type' => $type,
                'amount' => 0.00
            ];
        }

        $tenantCode = $this->s($transaction->tenant?->customer_code);
        $tenantName = $this->s($transaction->tenant?->name);
        if ($tenantCode === '') { $tenantCode = 'UNKNOWN_TENANT'; }
        if ($tenantName === '') { $tenantName = 'Unknown Tenant'; }
        $terminalSerial = $this->s($transaction->terminal?->serial_number);
        if ($terminalSerial === '') { $terminalSerial = 'UNKNOWN_TERMINAL'; }

        $payloadArr = [
            'tsms_id' => $transaction->id,
            'transaction_id' => (string)$transaction->transaction_id,
            'terminal_serial' => $terminalSerial,
            'tenant_code' => $tenantCode,
            'tenant_name' => $tenantName,
            'transaction_timestamp' => $this->isoTimestamp($transaction->transaction_timestamp),
            'amount' => (float) $transaction->gross_sales,
            'net_amount' => (float) $transaction->net_sales,
            'validation_status' => $this->s($transaction->validation_status),
            'processed_at' => $this->isoTimestamp($transaction->created_at),
            'submission_uuid' => $this->s($transaction->submission_uuid),
            'adjustments' => $this->normalizeAdjustments($completeAdjustments),
            'taxes' => $this->normalizeTaxes($completeTaxes),
        ];

        // Compute checksum
        unset($payloadArr['checksum']);
        $payloadArr['checksum'] = $this->checksumService->computeChecksum($payloadArr);

        // Build bulk payload format (even for single transaction)
        $batchId = 'TSMS_' . now()->format('YmdHis') . '_' . uniqid();
        $tenantId = $transaction->tenant_id;
        $terminalId = $transaction->terminal_id;
        $batchChecksum = $this->computeBatchChecksum(
            self::BULK_SCHEMA_VERSION,
            'TSMS',
            $batchId,
            (string)$tenantId,
            (string)$terminalId,
            1,
            [$payloadArr['checksum']]
        );
        $bulkPayload = [
            'source' => 'TSMS',
            'schema_version' => self::BULK_SCHEMA_VERSION,
            'batch_id' => $batchId,
            'timestamp' => Carbon::now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'transaction_count' => 1,
            'batch_checksum' => $batchChecksum,
            'transactions' => [$payloadArr],
        ];

        try {
            $client = Http::timeout($this->timeout)
                          ->withToken($this->authToken);

            if (!$this->verifySSL) {
                $client = $client->withoutVerifying();
            }

            $this->log('debug', '[TSMS] Immediate forwarding payload', $bulkPayload, $transaction);
            // Local validation for immediate single forward (converted to bulk format)
            $validation = $this->validateBulkPayload($bulkPayload);
            if (!$validation['valid']) {
                $this->log('error', 'Immediate forwarding validation failed', [
                    'transaction_id' => $transaction->transaction_id,
                    'errors' => $validation['errors'],
                    'classification' => self::CLASS_LOCAL_VALIDATION_FAIL,
                    'remediation' => RejectionPlaybook::explain('Outbound payload invalid'),
                ], $transaction);
                return [
                    'success' => false,
                    'error' => 'Outbound payload invalid',
                    'batch_id' => $batchId,
                    'classification' => self::CLASS_LOCAL_VALIDATION_FAIL,
                ];
            }

            if (config('tsms.testing.capture_only')) {
                // Simulate successful response and create forwarding record for test assertions
                WebappTransactionForward::create([
                    'transaction_id' => $transaction->id,
                    'batch_id' => $batchId,
                    'status' => WebappTransactionForward::STATUS_COMPLETED,
                    'attempts' => 1,
                    'max_attempts' => 1,
                    'first_attempted_at' => now(),
                    'last_attempted_at' => now(),
                    'completed_at' => now(),
                    'request_payload' => $payloadArr,
                    'response_data' => ['captured' => true],
                    'response_status_code' => 200,
                ]);
                Metrics::incr('forwarding.captured');
                return [
                    'success' => true,
                    'message' => 'Captured only (test mode)',
                    'batch_id' => $batchId,
                    'captured_payload' => $bulkPayload,
                ];
            }
            $response = $client->post($this->webAppEndpoint, $bulkPayload)->throw();

            $data = $response->json();

            // Create forwarding record
            WebappTransactionForward::create([
                'transaction_id' => $transaction->id,
                'batch_id' => $batchId,
                'status' => WebappTransactionForward::STATUS_COMPLETED,
                'attempts' => 1,
                'max_attempts' => 1,
                'first_attempted_at' => now(),
                'last_attempted_at' => now(),
                'completed_at' => now(),
                'request_payload' => $payloadArr,
                'response_data' => $data,
                'response_status_code' => $response->status(),
            ]);

            Metrics::incr('forwarding.success');
            $this->log('info', 'Transaction forwarded immediately', [
                'transaction_id' => $transaction->transaction_id,
                'batch_id' => $batchId,
            ], $transaction);

            return [
                'success' => true,
                'message' => 'Transaction forwarded successfully',
                'batch_id' => $batchId,
                'response_status' => $response->status()
            ];

        } catch (RequestException $e) {
            $msg = $e->getMessage();
            $status = $e->response?->status();
            $classification = $this->classifyHttpFailure($status, $msg);
            Metrics::incr('forwarding.failure');
            $this->log('error', 'Immediate forwarding HTTP error', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $msg,
                'status' => $status,
                'classification' => $classification,
            ], $transaction);
            $this->maybeRecordBreakerFailure($classification);

            // Create failed forwarding record
            WebappTransactionForward::create([
                'transaction_id' => $transaction->id,
                'batch_id' => $batchId,
                'status' => WebappTransactionForward::STATUS_FAILED,
                'attempts' => 1,
                'max_attempts' => 1,
                'first_attempted_at' => now(),
                'last_attempted_at' => now(),
                'request_payload' => $payloadArr,
                'error_message' => $msg,
                'response_status_code' => $e->response?->status(),
            ]);

            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId
            ];

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $classification = $this->classifyThrowable($msg);
            Metrics::incr('forwarding.failure');
            $this->log('error', 'Immediate forwarding exception', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $msg,
                'classification' => $classification,
            ], $transaction);
            $this->maybeRecordBreakerFailure($classification);

            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId
            ];
        }
    }
}