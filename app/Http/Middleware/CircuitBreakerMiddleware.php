<?php

namespace App\Http\Middleware;

use App\Services\CircuitBreaker;
use App\Support\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
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

        if (! $circuitBreaker->isAvailable()) {
            // T028b: attach an identical Retry-After value to both the JSON
            // body and the HTTP header, computed once — mirroring the
            // pattern IngestionBackpressureService already established for
            // the unrelated queue-backpressure rejection path. Covers both
            // rejection cases isAvailable() can return false for: circuit
            // OPEN, and HALF_OPEN with probe capacity exhausted.
            $retryAfterSeconds = $circuitBreaker->retryAfterSeconds();

            return response()->json([
                'error' => 'Service unavailable',
                'service' => $serviceKey,
                'message' => 'Circuit is open due to multiple failures',
                'retry_after_seconds' => $retryAfterSeconds,
                'correlation_id' => $request->attributes->get('correlation_id'),
            ], 503)->header('Retry-After', (string) $retryAfterSeconds);
        }

        // If this request was admitted as a half-open probe, stash the
        // admitted generation so it can be threaded through to
        // recordSuccess()/recordFailure() after the handler runs —
        // mirroring how circuit_breaker.downstream_attempted is already
        // propagated below. Null (unset) when the circuit was simply
        // closed, i.e. no half-open admission occurred.
        $halfOpenGeneration = $circuitBreaker->currentHalfOpenGeneration();
        if ($halfOpenGeneration !== null) {
            $request->attributes->set('circuit_breaker.half_open_generation', $halfOpenGeneration);
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
                $circuitBreaker->recordFailure($request->attributes->get('circuit_breaker.half_open_generation'));
            } else {
                Log::error('CircuitBreakerMiddleware: exception before downstream attempt, not recorded against breaker', LogContext::ingestion([
                    'route' => $request->path(),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                    'decision' => 'not_recorded',
                    'reason' => 'exception_before_downstream_attempt',
                ], [
                    'service' => $serviceKey,
                    'error' => $e->getMessage(),
                ]));
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
            $generation = $request->attributes->get('circuit_breaker.half_open_generation');
            if ($response->getStatusCode() >= 500) {
                $circuitBreaker->recordFailure($generation);
            } else {
                $circuitBreaker->recordSuccess($generation);
            }
        }

        return $response;
    }
}
