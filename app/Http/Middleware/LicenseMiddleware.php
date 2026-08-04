<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseAuditLogger;
use App\Services\Licensing\LicenseService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LicenseMiddleware
{
    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly LicenseAuditLogger $auditLogger,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->licenseChecksEnabled()) {
            return $next($request);
        }

        $mode = $this->enforcementMode();
        if ($mode === 'disabled') {
            return $next($request);
        }

        $result = $this->licenseService->validateLicense();
        if ($result->valid) {
            return $next($request);
        }

        $context = array_merge($result->license?->safeSummary() ?? [], $result->context, [
            'enforcement_mode' => $mode,
        ]);

        if ($mode === 'observe') {
            $this->auditLogger->log(
                'LICENSE_OBSERVED_VIOLATION',
                $result->reasonCode,
                $context,
                $request
            );

            return $next($request);
        }

        $this->auditLogger->log(
            'LICENSE_ENFORCEMENT_DENIED',
            $result->reasonCode,
            $context,
            $request
        );

        return new JsonResponse([
            'success' => false,
            'error_code' => $result->reasonCode->value,
            'message' => 'The system license is expired or invalid. Please contact the vendor.',
            'trace_id' => $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id'),
        ], 403);
    }

    private function licenseChecksEnabled(): bool
    {
        return (bool) config('license.enabled', true);
    }

    private function enforcementMode(): string
    {
        $mode = (string) config('license.enforcement_mode', 'observe');
        $allowedModes = config('license.allowed_enforcement_modes', [
            'disabled',
            'observe',
            'restricted',
            'enforce',
        ]);

        return in_array($mode, $allowedModes, true) ? $mode : 'observe';
    }
}
