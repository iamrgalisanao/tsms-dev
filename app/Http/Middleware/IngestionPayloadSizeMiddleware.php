<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects oversized ingestion requests before any expensive structural
 * validation, checksum verification, or DB work happens.
 *
 * This is intentionally a pure Content-Length check with zero I/O so it can
 * run ahead of (and cheaper than) IngestionBackpressureMiddleware's Redis
 * lookup. It must stay first in the ingestion middleware chain on both
 * /transactions/batch and /transactions/official.
 */
class IngestionPayloadSizeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('tsms.intake.max_payload_bytes');

        $contentLength = $request->headers->has('Content-Length')
            ? (int) $request->header('Content-Length')
            : strlen((string) $request->getContent());

        if ($maxBytes > 0 && $contentLength > $maxBytes) {
            $correlationId = $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id');

            return response()->json([
                'success' => false,
                'error_code' => 'PAYLOAD_TOO_LARGE',
                'message' => 'Request payload exceeds the maximum allowed size.',
                'max_payload_bytes' => $maxBytes,
                'correlation_id' => $correlationId,
            ], 413);
        }

        return $next($request);
    }
}
