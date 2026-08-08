<?php

namespace App\Http\Middleware;

use App\Models\PosTerminal;
use App\Services\IngestionBackpressureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IngestionBackpressureMiddleware
{
    public function __construct(private readonly IngestionBackpressureService $backpressureService)
    {
    }

    public function handle(Request $request, Closure $next, string $queueType = 'processing'): Response
    {
        $tenantId = $request->user() instanceof PosTerminal
            ? $request->user()->tenant_id
            : $request->input('tenant_id');

        $backpressure = $queueType === 'intake'
            ? $this->backpressureService->checkIntake($tenantId)
            : $this->backpressureService->checkProcessing($tenantId);

        $request->attributes->set('backpressure.' . $backpressure['queue_type'], $backpressure);

        if (!$backpressure['enforced']) {
            return $next($request);
        }

        $correlationId = $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id');

        if ($backpressure['degraded']) {
            $payload = $this->backpressureService->degradedPayload($backpressure, $correlationId);

            return response()
                ->json($payload, $this->backpressureService->degradedStatus())
                ->header('Retry-After', (string) $payload['retry_after_seconds']);
        }

        $payload = $this->backpressureService->rejectionPayload($backpressure, $correlationId);

        return response()
            ->json($payload, $this->backpressureService->rejectionStatus())
            ->header('Retry-After', (string) $payload['retry_after_seconds']);
    }
}
