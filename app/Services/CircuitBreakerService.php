<?php

namespace App\Services;

use App\Models\CircuitBreaker;
use Exception;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    public function execute(string $serviceName, int $tenantId, callable $action)
    {
        $circuitBreaker = CircuitBreaker::where('name', $serviceName)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (!$circuitBreaker->isAvailable()) {
            throw new Exception("Service {$serviceName} is currently unavailable");
        }

        try {
            $result = $action();
            return $result;
        } catch (Exception $e) {
            $circuitBreaker->trip();
            throw $e;
        }
    }

    public function recordFailure(string $service, int $tenantId): void
    {
        $circuitBreaker = CircuitBreaker::firstOrCreate(
            ['name' => $service, 'tenant_id' => $tenantId],
            [
                'status' => 'CLOSED',
                'failure_threshold' => config('app.circuit_breaker.failure_threshold'),
                'trip_count' => 0
            ]
        );

        $circuitBreaker->increment('trip_count');
        $circuitBreaker->last_failure_at = now();

        if ($circuitBreaker->trip_count >= $circuitBreaker->failure_threshold) {
            $circuitBreaker->status = 'OPEN';
            $circuitBreaker->cooldown_until = now()->addMinutes((int)
                (int) config('security.circuit_breaker.cooldown_minutes')
            );
        }

        $circuitBreaker->save();

        Log::warning("Circuit breaker failure recorded", [
            'service' => $service,
            'tenant_id' => $tenantId,
            'status' => $circuitBreaker->status,
            'trip_count' => $circuitBreaker->trip_count
        ]);
    }

    public function reset(string $service, int $tenantId): bool
    {
        $circuitBreaker = CircuitBreaker::where('name', $service)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$circuitBreaker) {
            return false;
        }

        $circuitBreaker->update([
            'status' => 'CLOSED',
            'trip_count' => 0,
            'last_failure_at' => null,
            'cooldown_until' => null
        ]);

        Log::info("Circuit breaker reset", [
            'service' => $service,
            'tenant_id' => $tenantId
        ]);

        return true;
    }
}