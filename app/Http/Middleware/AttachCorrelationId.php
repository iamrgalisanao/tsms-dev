<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttachCorrelationId
{
    public function handle(Request $request, Closure $next)
    {
        $cid = $this->resolveCorrelationId($request);

        // Attach to request for downstream usage. This request attribute is
        // the single canonical source of truth — every other service/
        // middleware must read it here rather than re-deriving a
        // correlation ID from raw headers itself.
        $request->attributes->set('correlation_id', $cid);

        $response = $next($request);

        // Always echo back the chosen value on one canonical response header.
        $response->headers->set('X-Request-Id', $cid);

        return $response;
    }

    /**
     * Deterministic ingress precedence for the canonical correlation ID:
     *
     * 1. an existing trusted request attribute (if already set upstream)
     * 2. X-Request-Id
     * 3. X-Correlation-ID (compatibility fallback only, for any external
     *    integration still sending this header)
     * 4. a generated UUID
     *
     * If both X-Request-Id and X-Correlation-ID are present with different
     * values, X-Request-Id wins and a bounded warning is logged noting the
     * mismatch — no downstream service is allowed to pick independently.
     */
    private function resolveCorrelationId(Request $request): string
    {
        $existing = $request->attributes->get('correlation_id');
        if (! empty($existing)) {
            return $existing;
        }

        $requestId = $request->header('X-Request-Id');
        $correlationHeader = $request->header('X-Correlation-ID');

        if (! empty($requestId)) {
            if (! empty($correlationHeader) && $correlationHeader !== $requestId) {
                Log::warning('AttachCorrelationId: X-Request-Id and X-Correlation-ID both present with different values; preferring X-Request-Id', [
                    'x_request_id' => $requestId,
                    'x_correlation_id' => $correlationHeader,
                ]);
            }

            return $requestId;
        }

        if (! empty($correlationHeader)) {
            return $correlationHeader;
        }

        return (string) Str::uuid();
    }
}
