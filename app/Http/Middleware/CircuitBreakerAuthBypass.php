<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\CircuitBreaker;
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
                // Circuit is open, return 503 immediately
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
