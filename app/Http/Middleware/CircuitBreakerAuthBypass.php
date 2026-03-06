<?php

namespace App\Http\Middleware;

use App\Models\CircuitBreaker;
use App\Models\SystemLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CircuitBreakerAuthBypass
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $service
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $service = 'api.transactions')
    {
        // Get tenant ID from request or use default for testing
        $tenantId = $request->header('X-Tenant-ID', 1);

        // Check if circuit is open for this service and tenant
        $circuitBreaker = CircuitBreaker::where('tenant_id', $tenantId)
            ->where('name', $service)
            ->first();

        if ($circuitBreaker && $circuitBreaker->status === CircuitBreaker::STATUS_OPEN) {
            if (now()->gt($circuitBreaker->cooldown_until)) {
                // Transition to HALF_OPEN after cooldown
                $circuitBreaker->status = CircuitBreaker::STATUS_HALF_OPEN;
                $circuitBreaker->save();
            } else {
                // Circuit is open, record structured log and return 503 immediately
                try {
                    SystemLog::create([
                        'type' => 'circuit_breaker',
                        'log_type' => 'CIRCUIT_BREAKER_OPEN_REJECT',
                        'severity' => 'error',
                        'terminal_uid' => null,
                        'transaction_id' => null,
                        'message' => 'Circuit breaker open – request rejected',
                        'context' => [
                            'tenant_id' => $tenantId,
                            'service' => $service,
                            'retry_at' => optional($circuitBreaker->cooldown_until)->toIso8601String(),
                            'path' => $request->path(),
                        ],
                    ]);
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to write SystemLog for CIRCUIT_BREAKER_OPEN_REJECT', [
                        'tenant_id' => $tenantId,
                        'service' => $service,
                        'error' => $logEx->getMessage(),
                    ]);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Circuit breaker is open',
                    'service' => $service,
                    'retry_at' => $circuitBreaker->cooldown_until
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        return $next($request);
    }
}
