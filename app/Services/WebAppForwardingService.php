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
            ->with(['terminal', 'tenant', 'jobs'])
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
            $payloadArr = [
                'tsms_id'               => $tx->id,
                'transaction_id'        => $tx->transaction_id,
                'terminal_serial'       => $tx->terminal?->serial_number,
                'tenant_code'           => $tx->tenant?->customer_code,
                'tenant_name'           => $tx->tenant?->name,
                'transaction_timestamp' => $this->isoTimestamp($tx->transaction_timestamp),
                'amount'                => (float) $tx->base_amount,
                'validation_status'     => $tx->validation_status,
                'processed_at'          => $this->isoTimestamp($tx->created_at),
                'submission_uuid'       => $tx->submission_uuid,
            ];
            // Remove checksum if present, then compute
            unset($payloadArr['checksum']);
            $payloadArr['checksum'] = $this->checksumService->computeChecksum($payloadArr);

            $forward = WebappTransactionForward::firstOrNew(
                [
                    'transaction_id' => $tx->id,
                    'submission_uuid' => $tx->submission_uuid,
                ],
                [
                    'batch_id'        => $batchId,
                    'status'          => WebappTransactionForward::STATUS_PENDING,
                    'max_attempts'    => 3,
                    'request_payload' => $payloadArr,
                ]
            );

            if ($forward->exists && ! in_array($forward->status, [
                WebappTransactionForward::STATUS_PENDING,
                WebappTransactionForward::STATUS_FAILED,
            ])) {
                $forward->batch_id        = $batchId;
                $forward->request_payload = $payloadArr;
            }

            $forward->save();
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

            $data = $response->json();
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
        $records->each(fn ($f) => $f->markAsFailed($error, $statusCode));
    }

    // buildTransactionPayload is now inlined in createForwardingRecords to ensure checksum is always up-to-date

    private function buildBulkPayload(Collection $records, string $batchId): array
    {
        return [
            'source'            => 'TSMS',
            'batch_id'          => $batchId,
            'timestamp'         => Carbon::now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'transaction_count' => $records->count(),
            'transactions'      => $records->pluck('request_payload')->all(),
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
        Cache::put($this->circuitBreakerKey.'_last_failure', now(), now()->addHour());
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
}