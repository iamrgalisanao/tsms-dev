<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    /** Shared key used by the official/batch ingestion breaker (route param 'transaction-intake'). */
    public const INGESTION_SERVICE_KEY = 'transaction-intake';

    protected string $serviceKey;
    protected string $redisConnection;
    protected string $key;
    protected int $failureThreshold;
    protected int $resetTimeoutSeconds;
    protected int $stateTtlSeconds;
    protected bool $enabled;

    public function __construct(string $serviceKey)
    {
        $this->serviceKey = $serviceKey;
        $this->redisConnection = (string) config('tsms.circuit_breaker.redis_connection', 'default');
        $this->key = config('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:') . $serviceKey;
        $this->failureThreshold = max(1, (int) config('tsms.circuit_breaker.failure_threshold', 5));
        $this->resetTimeoutSeconds = max(1, (int) config('tsms.circuit_breaker.reset_timeout_seconds', 60));
        $this->stateTtlSeconds = max($this->resetTimeoutSeconds, (int) config('tsms.circuit_breaker.state_ttl_seconds', 3600));
        $this->enabled = (bool) config('tsms.circuit_breaker.enabled', true);
    }

    public function isAvailable(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        try {
            $state = $this->readState();
        } catch (\Throwable $e) {
            // Breaker bookkeeping itself depends on Redis; if Redis is down we
            // cannot reliably evaluate breaker state. Fail open here — a
            // Redis outage is independently handled by
            // IngestionBackpressureService's fail-closed path (T034) in
            // enforce mode, so traffic doesn't pass through unchecked.
            Log::error('CircuitBreaker: failed to read state, failing open', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
            return true;
        }

        if ($state['state'] === self::STATE_OPEN) {
            if ($state['opened_at'] > 0 && (now()->timestamp - $state['opened_at']) >= $this->resetTimeoutSeconds) {
                $this->transitionToHalfOpen();
                return true;
            }
            return false;
        }

        return true; // closed or half-open both allow the request through
    }

    public function recordSuccess(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $state = $this->readState();
            if ($state['state'] !== self::STATE_CLOSED) {
                $this->reset();
            }
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to record success', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recordFailure(): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $connection = Redis::connection($this->redisConnection);

            // hincrby must happen first (and alone) so we know the resulting
            // failure count before deciding whether to open the breaker.
            $failureCount = (int) $connection->hincrby($this->key, 'failure_count', 1);
            $opening = $failureCount >= $this->failureThreshold;
            $now = now()->timestamp;

            // Write the rest of this update inside a genuine MULTI/EXEC
            // transaction (Predis's Client::transaction(), not pipeline() —
            // pipeline() only batches round-trips for performance and gives
            // no all-or-nothing guarantee). If 'state' and 'opened_at' were
            // written non-atomically, a crash between them could leave
            // state=open with a stale/zero opened_at — and
            // isAvailable()'s half-open transition requires opened_at > 0,
            // so the breaker could get stuck open until the whole key's
            // TTL expires.
            $connection->transaction(function ($tx) use ($now, $opening) {
                $tx->hset($this->key, 'last_failure_at', $now);
                if ($opening) {
                    $tx->hset($this->key, 'state', self::STATE_OPEN);
                    $tx->hset($this->key, 'opened_at', $now);
                }
                $tx->expire($this->key, $this->stateTtlSeconds);
            });

            if ($opening) {
                Log::warning('CircuitBreaker: opened', [
                    'service' => $this->serviceKey,
                    'failure_count' => $failureCount,
                    'failure_threshold' => $this->failureThreshold,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to record failure', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(): void
    {
        try {
            $connection = Redis::connection($this->redisConnection);
            $now = now()->timestamp;

            // Genuine MULTI/EXEC transaction — see the comment in
            // recordFailure() for why pipeline() was insufficient here.
            $connection->transaction(function ($tx) use ($now) {
                $tx->hset($this->key, 'state', self::STATE_CLOSED);
                $tx->hset($this->key, 'failure_count', 0);
                $tx->hset($this->key, 'opened_at', 0);
                $tx->hset($this->key, 'last_success_at', $now);
                $tx->expire($this->key, $this->stateTtlSeconds);
            });
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to reset', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function transitionToHalfOpen(): void
    {
        try {
            $connection = Redis::connection($this->redisConnection);
            $connection->hset($this->key, 'state', self::STATE_HALF_OPEN);
            $connection->expire($this->key, $this->stateTtlSeconds);
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to transition to half-open', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function readState(): array
    {
        $data = Redis::connection($this->redisConnection)->hgetall($this->key);

        return [
            'state' => $data['state'] ?? self::STATE_CLOSED,
            'failure_count' => (int) ($data['failure_count'] ?? 0),
            'opened_at' => (int) ($data['opened_at'] ?? 0),
        ];
    }
}
