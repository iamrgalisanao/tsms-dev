<?php
namespace App\Services;

use App\Services\PayloadChecksumService;

use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class WebAppForwardingService
{
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

    public function __construct()
    {
        $this->webAppEndpoint           = config('tsms.web_app.endpoint', '');
        $this->timeout                  = config('tsms.web_app.timeout', 30);
        $this->batchSize                = config('tsms.web_app.batch_size', 50);
        $this->authToken                = config('tsms.web_app.auth_token', '');
        $this->verifySSL                = config('tsms.web_app.verify_ssl', false);
        $this->circuitBreakerEnabled    = config('tsms.circuit_breaker.enabled', true);
        $this->circuitBreakerThreshold  = config('tsms.circuit_breaker.threshold', 5);
        $this->circuitBreakerCooldown   = config('tsms.circuit_breaker.cooldown', 10);
        $this->checksumService          = app(PayloadChecksumService::class);
    }

    public function forwardUnsentTransactions(): array
    {
        return $this->processUnforwardedTransactions();
    }

    public function processUnforwardedTransactions(): array
    {
        $this->assertEndpoint();

        if ($this->circuitBreakerEnabled && $this->isCircuitBreakerOpen()) {
            Log::warning('WebApp forwarding skipped – circuit breaker OPEN');
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
                Log::info('Idempotent forwarding: submission already processed', ['submission_uuid' => $submissionUuid]);
                return [
                    'success' => true,
                    'forwarded_count' => $existing->count(),
                    'batch_id' => $existing->first()->batch_id,
                    'idempotent' => true
                ];
            }
        }

        $forwarding = $this->createForwardingRecords($records);
        return $this->processBatchForwarding($forwarding);
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
                'transaction_id' => $tx->transaction_id,
                'terminal_serial' => $tx->terminal?->serial_number,
                'tenant_code' => $tx->tenant?->customer_code,
                'tenant_name' => $tx->tenant?->name,
                'transaction_timestamp' => $this->isoTimestamp($tx->transaction_timestamp),
                'amount' => (float) $tx->gross_sales,
                'net_amount' => (float) $tx->net_sales,
                'validation_status' => $tx->validation_status,
                'processed_at' => $this->isoTimestamp($tx->created_at),
                'submission_uuid' => $tx->submission_uuid,
                'adjustments' => $completeAdjustments,
                'taxes' => $completeTaxes,
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
    private function processBatchForwarding(Collection $records): array
    {
        $batchId = $records->first()->batch_id;
        $records->each(fn ($f) => $f->markAsInProgress());

        $payload = $this->buildBulkPayload($records, $batchId);

        try {
            $client = Http::timeout($this->timeout)
                          ->withToken($this->authToken);

            if (! $this->verifySSL) {
                $client = $client->withoutVerifying();
            }

            Log::debug('[TSMS] Forwarding payload', $payload);
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

            Log::info('Bulk forwarded', ['batch_id' => $batchId, 'count' => $records->count()]);
            return ['success' => true, 'forwarded_count' => $records->count(), 'batch_id' => $batchId];

        } catch (RequestException $e) {
            $msg = $e->getMessage();
            Log::error('HTTP forwarding error', ['error' => $msg]);
            $this->recordFailure();
            $this->handleBatchFailure($records, $msg, $e->response?->status());
            return ['success' => false, 'error' => $msg, 'batch_id' => $batchId];

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Log::error('Forwarding exception', ['error' => $msg]);
            $this->recordFailure();
            $this->handleBatchFailure($records, $msg);
            return ['success' => false, 'error' => $msg, 'batch_id' => $batchId];
        }
    }

    private function handleBatchFailure(Collection $records, string $error, int $statusCode = null): void
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
        // For single transaction forwarding, use the user's specified structure
        if ($records->count() === 1) {
            $forwardingRecord = $records->first();
            $transaction = $forwardingRecord->transaction;

            return [
                'submission_uuid' => $transaction->submission_uuid,
                'tenant_id' => $transaction->tenant_id,
                'terminal_id' => $transaction->terminal_id,
                'submission_timestamp' => $this->isoTimestamp($transaction->submission_timestamp),
                'transaction_count' => 1,
                'payload_checksum' => $forwardingRecord->request_payload['checksum'] ?? '',
                'transaction' => [
                    'transaction_id' => $transaction->transaction_id,
                    'transaction_timestamp' => $this->isoTimestamp($transaction->transaction_timestamp),
                    'gross_sales' => (float) $transaction->gross_sales,
                    'net_sales' => (float) $transaction->net_sales,
                    'promo_status' => $transaction->promo_status,
                    'customer_code' => $transaction->customer_code,
                    'payload_checksum' => $forwardingRecord->request_payload['checksum'] ?? '',
                    'adjustments' => $forwardingRecord->request_payload['adjustments'] ?? [],
                    'taxes' => $forwardingRecord->request_payload['taxes'] ?? [],
                ]
            ];
        }

        // For multiple transactions, use the original batch structure
        return [
            'source' => 'TSMS',
            'batch_id' => $batchId,
            'timestamp' => Carbon::now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'transaction_count' => $records->count(),
            'transactions' => $records->pluck('request_payload')->all(),
        ];
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

        $payloadArr = [
            'tsms_id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            'terminal_serial' => $transaction->terminal?->serial_number,
            'tenant_code' => $transaction->tenant?->customer_code,
            'tenant_name' => $transaction->tenant?->name,
            'transaction_timestamp' => $this->isoTimestamp($transaction->transaction_timestamp),
            'amount' => (float) $transaction->gross_sales,
            'net_amount' => (float) $transaction->net_sales,
            'validation_status' => $transaction->validation_status,
            'processed_at' => $this->isoTimestamp($transaction->created_at),
            'submission_uuid' => $transaction->submission_uuid,
            'adjustments' => $completeAdjustments,
            'taxes' => $completeTaxes,
        ];

        // Compute checksum
        unset($payloadArr['checksum']);
        $payloadArr['checksum'] = $this->checksumService->computeChecksum($payloadArr);

        // Build bulk payload format (even for single transaction)
        $batchId = 'TSMS_' . now()->format('YmdHis') . '_' . uniqid();
        $bulkPayload = [
            'source' => 'TSMS',
            'batch_id' => $batchId,
            'timestamp' => Carbon::now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'transaction_count' => 1,
            'transactions' => [$payloadArr],
        ];

        try {
            $client = Http::timeout($this->timeout)
                          ->withToken($this->authToken);

            if (!$this->verifySSL) {
                $client = $client->withoutVerifying();
            }

            Log::debug('[TSMS] Immediate forwarding payload', $bulkPayload);
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

            Log::info('Transaction forwarded immediately', [
                'transaction_id' => $transaction->transaction_id,
                'batch_id' => $batchId
            ]);

            return [
                'success' => true,
                'message' => 'Transaction forwarded successfully',
                'batch_id' => $batchId,
                'response_status' => $response->status()
            ];

        } catch (RequestException $e) {
            $msg = $e->getMessage();
            Log::error('Immediate forwarding HTTP error', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $msg
            ]);

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
            Log::error('Immediate forwarding exception', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $msg
            ]);

            return [
                'success' => false,
                'error' => $msg,
                'batch_id' => $batchId
            ];
        }
    }
}