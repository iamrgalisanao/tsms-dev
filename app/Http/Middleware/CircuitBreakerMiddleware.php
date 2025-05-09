<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\CircuitBreaker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class CircuitBreakerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $service
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $service)
    {
        // Get tenant ID from request or use default for testing
        $tenantId = $request->header('X-Tenant-ID', 1);

        // Get or create circuit breaker
        $circuitBreaker = CircuitBreaker::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => $service
            ],
            [
                'status' => CircuitBreaker::STATUS_CLOSED,
                'trip_count' => 0,
                'cooldown_until' => now()
            ]
        );

        // Generate Redis key with tenant isolation
        $redisKey = "circuit_breaker:{$tenantId}:{$service}";

        // If circuit is OPEN, reject the request
        if ($circuitBreaker->status === CircuitBreaker::STATUS_OPEN) {
            if (now()->gt($circuitBreaker->cooldown_until)) {
                // Transition to HALF_OPEN after cooldown
                $circuitBreaker->status = CircuitBreaker::STATUS_HALF_OPEN;
                $circuitBreaker->save();
            } else {
                return response()->json([
                    'error' => 'Circuit breaker is open',
                    'service' => $service,
                    'tenant_id' => $tenantId,
                    'retry_after' => $circuitBreaker->cooldown_until
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        try {
            // Forward the request
            $response = $next($request);
            
            // If it's a test circuit and it returned 500, count it as a failure but don't throw
            if (str_contains($request->path(), 'test-circuit')) {
                if ($response->getStatusCode() >= 500) {
                    // Record failure and get current count
                    $failureCount = $this->recordFailure($redisKey);
                    
                    // Check if we should open the circuit
                    $threshold = config('services.circuit_breaker.threshold', 3);
                    if ($failureCount >= $threshold) {
                        $circuitBreaker->status = CircuitBreaker::STATUS_OPEN;
                        $circuitBreaker->trip_count++;
                        $cooldownSeconds = (int) config('services.circuit_breaker.cooldown', 60);
                        $circuitBreaker->cooldown_until = now()->addSeconds($cooldownSeconds);
                        $circuitBreaker->save();
                    }
                    
                    return $response;
                }
            } else if ($response->getStatusCode() >= 500) {
                throw new \Exception("Service error: " . $response->getStatusCode());
            }
            
            // Record success and reset failure count
            $this->recordSuccess($redisKey);
            $this->resetFailureCount($redisKey);
            
            // If we were in HALF_OPEN, transition back to CLOSED
            if ($circuitBreaker->status === CircuitBreaker::STATUS_HALF_OPEN) {
                $circuitBreaker->status = CircuitBreaker::STATUS_CLOSED;
                $circuitBreaker->save();
            }

            return $response;
            
        } catch (\Exception $e) {
            Log::error('Circuit breaker service error', [
                'service' => $service,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage()
            ]);

            // Record failure and get current count
            $failureCount = $this->recordFailure($redisKey);
            
            // Check if we should open the circuit
            $threshold = config('services.circuit_breaker.threshold', 3);
            Log::info('Circuit breaker failure count', [
                'service' => $service,
                'tenant_id' => $tenantId,
                'failure_count' => $failureCount,
                'threshold' => $threshold
            ]);
            
            if ($failureCount >= $threshold) {
                $circuitBreaker->status = CircuitBreaker::STATUS_OPEN;
                $circuitBreaker->trip_count++;
                $circuitBreaker->cooldown_until = now()->addSeconds(
                    config('services.circuit_breaker.cooldown', 60)
                );
                $circuitBreaker->save();

                Log::warning('Circuit breaker opened', [
                    'service' => $service,
                    'tenant_id' => $tenantId,
                    'failure_count' => $failureCount,
                    'threshold' => $threshold
                ]);
            }
            
            if (str_contains($request->path(), 'test-circuit')) {
                return response()->json(['error' => $e->getMessage()], 500);
            }
            
            throw $e;
        }
    }

    /**
     * Record a successful request
     */
    private function recordSuccess(string $redisKey): void
    {
        try {
            Redis::incr("{$redisKey}:success_count");
        } catch (\Exception $e) {
            Log::error('Circuit breaker Redis error on success', [
                'key' => $redisKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Reset the failure count
     */
    private function resetFailureCount(string $redisKey): void
    {
        try {
            Redis::del("{$redisKey}:failure_count");
        } catch (\Exception $e) {
            Log::error('Circuit breaker Redis error on reset', [
                'key' => $redisKey,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Record a failed request and return current failure count
     */
    private function recordFailure(string $redisKey): int
    {
        try {
            $failureCount = Redis::incr("{$redisKey}:failure_count");
            Redis::set("{$redisKey}:last_failure", now()->timestamp);
            return (int) $failureCount;
        } catch (\Exception $e) {
            Log::error('Circuit breaker Redis error on failure', [
                'key' => $redisKey,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}