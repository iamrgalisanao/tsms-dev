<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    private const STATE_CLOSED = 'CLOSED';
    private const STATE_OPEN = 'OPEN';
    private const STATE_HALF_OPEN = 'HALF_OPEN';

    private string $serviceKey;
    private int $failureThreshold;
    private int $retryTimeoutSeconds;
    private int $monitoringWindowSeconds;

    public function __construct(
        string $serviceKey,
        int $failureThreshold = 5,
        int $retryTimeoutSeconds = 30,
        int $monitoringWindowSeconds = 120
    ) {
        $this->serviceKey = $serviceKey;
        $this->failureThreshold = $failureThreshold;
        $this->retryTimeoutSeconds = $retryTimeoutSeconds;
        $this->monitoringWindowSeconds = $monitoringWindowSeconds;
    }

    public function execute(callable $operation)
    {
        $this->ensureCircuitExists();

        if ($this->isOpen()) {
            if ($this->canReset()) {
                $this->transitionToHalfOpen();
            } else {
                throw new Exception("Circuit breaker is OPEN for service: {$this->serviceKey}");
            }
        }

        try {
            $result = $operation();
            
            if ($this->getState() === self::STATE_HALF_OPEN) {
                $this->reset();
            }
            
            return $result;
        } catch (Exception $e) {
            $this->recordFailure();
            throw $e;
        }
    }

    public function isOpen(): bool
    {
        return $this->getState() === self::STATE_OPEN;
    }

    public function getState(): string
    {
        return Cache::get($this->getStateKey(), self::STATE_CLOSED);
    }

    public function reset(): void
    {
        Cache::put($this->getStateKey(), self::STATE_CLOSED);
        Cache::put($this->getFailureCountKey(), 0);
        Cache::put($this->getLastFailureKey(), null);
        
        Log::info("Circuit breaker reset", [
            'service' => $this->serviceKey,
            'state' => self::STATE_CLOSED
        ]);
    }

    private function recordFailure(): void
    {
        $failureCount = $this->incrementFailureCount();
        $now = time();

        Cache::put($this->getLastFailureKey(), $now);

        if ($failureCount >= $this->failureThreshold) {
            Cache::put($this->getStateKey(), self::STATE_OPEN);
            
            Log::warning("Circuit breaker opened", [
                'service' => $this->serviceKey,
                'failures' => $failureCount,
                'threshold' => $this->failureThreshold
            ]);
        }
    }

    private function canReset(): bool
    {
        $lastFailure = Cache::get($this->getLastFailureKey());
        return $lastFailure && (time() - $lastFailure) >= $this->retryTimeoutSeconds;
    }

    private function transitionToHalfOpen(): void
    {
        Cache::put($this->getStateKey(), self::STATE_HALF_OPEN);
        
        Log::info("Circuit breaker half-open", [
            'service' => $this->serviceKey
        ]);
    }

    private function incrementFailureCount(): int
    {
        return Cache::increment($this->getFailureCountKey());
    }

    private function ensureCircuitExists(): void
    {
        if (!Cache::has($this->getStateKey())) {
            $this->reset();
        }
    }

    private function getStateKey(): string
    {
        return "circuit_breaker:{$this->serviceKey}:state";
    }

    private function getFailureCountKey(): string
    {
        return "circuit_breaker:{$this->serviceKey}:failures";
    }

    private function getLastFailureKey(): string
    {
        return "circuit_breaker:{$this->serviceKey}:last_failure";
    }
}