<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use App\Services\CircuitBreaker;
use Symfony\Component\HttpFoundation\Response;

class CircuitBreakerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $serviceKey = 'default'): Response
    {
        // Create the CircuitBreaker with the service key explicitly
        $circuitBreaker = App::makeWith(CircuitBreaker::class, ['serviceKey' => $serviceKey]);

        if (!$circuitBreaker->isAvailable()) {
            return response()->json([
                'error' => 'Service unavailable',
                'service' => $serviceKey,
                'message' => 'Circuit is open due to multiple failures'
            ], 503);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Same gate as the success/failure branch below: only tell the
            // breaker about this if the request actually reached the
            // protected downstream dependency. A \TypeError/\Error thrown
            // during early validation/checksum logic (which bypasses
            // TransactionIntakeService's own \Exception-only catch) never
            // attempted the downstream resource and must not falsely
            // contribute to opening the breaker.
            if ($request->attributes->get('circuit_breaker.downstream_attempted', true)) {
                $circuitBreaker->recordFailure();
            } else {
                Log::error('CircuitBreakerMiddleware: exception before downstream attempt, not recorded against breaker', [
                    'service' => $serviceKey,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        }

        // Only record an outcome when the request actually attempted the
        // protected downstream dependency. Requests that short-circuited
        // before that point (validation rejection, backpressure rejection,
        // a degraded health check) prove nothing about the breaker's
        // protected resource and must not be recorded either way. Default
        // is true so any code path that doesn't set this attribute (e.g.
        // batchStore()) preserves today's behavior.
        if ($request->attributes->get('circuit_breaker.downstream_attempted', true)) {
            if ($response->getStatusCode() >= 500) {
                $circuitBreaker->recordFailure();
            } else {
                $circuitBreaker->recordSuccess();
            }
        }

        return $response;
    }
}