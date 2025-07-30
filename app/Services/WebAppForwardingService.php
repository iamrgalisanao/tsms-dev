<?php

namespace App\Services;

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
            $forward = WebappTransactionForward::firstOrNew(
                ['transaction_id' => $tx->id],
                [
                    'batch_id'        => $batchId,
                    'status'          => WebappTransactionForward::STATUS_PENDING,
                    'max_attempts'    => 3,
                    'request_payload' => $this->buildTransactionPayload($tx),
                ]
            );

            if ($forward->exists && ! in_array($forward->status, [
                WebappTransactionForward::STATUS_PENDING,
                WebappTransactionForward::STATUS_FAILED,
            ])) {
                $forward->batch_id        = $batchId;
                $forward->request_payload = $this->buildTransactionPayload($tx);
            }

            $forward->save();
            return $forward->load('transaction.terminal', 'transaction.tenant');
        });
    }

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

    private function buildTransactionPayload(Transaction $tx): array
    {
        return [
            'tsms_id'               => $tx->id,
            'transaction_id'        => $tx->transaction_id,
            'terminal_serial'       => $tx->terminal?->serial_number,
            'tenant_code'           => $tx->tenant?->customer_code,
            'tenant_name'           => $tx->tenant?->name,
            'transaction_timestamp' => $this->isoTimestamp($tx->transaction_timestamp),
            'amount'                => (float) $tx->base_amount,
            'validation_status'     => $tx->validation_status,
            'processed_at'          => $this->isoTimestamp($tx->created_at),
            'checksum'              => $tx->payload_checksum,
            'submission_uuid'       => $tx->submission_uuid,
        ];
    }

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
