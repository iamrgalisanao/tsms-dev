<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenseAuditLogger;
use App\Services\Licensing\LicenseRecoveryRequestService;
use App\Services\Licensing\LicenseReplacementService;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LicenseController extends Controller
{
    public function __construct(
        private readonly LicenseService $licenseService,
        private readonly LicenseRecoveryRequestService $recoveryRequestService,
    ) {
    }

    public function status(): JsonResponse
    {
        $status = $this->safeStatusPayload($this->licenseService->getLicenseStatus());

        return new JsonResponse([
            'success' => true,
            'data' => array_merge($status, [
                'enforcement_mode' => config('license.enforcement_mode'),
                'license_enabled' => (bool) config('license.enabled'),
            ]),
        ]);
    }

    public function capabilities(): JsonResponse
    {
        $status = $this->safeStatusPayload($this->licenseService->getLicenseStatus());

        return new JsonResponse([
            'success' => true,
            'data' => [
                'license_valid' => $status['license_valid'],
                'reason_code' => $status['reason_code'],
                'deployment_id' => $status['deployment_id'],
                'licensed_locations' => $status['licensed_locations'],
                'expires_at' => $status['expires_at'],
                'enforcement_mode' => config('license.enforcement_mode'),
                'capabilities' => [
                    'redeployment_control' => true,
                    'license_upload' => true,
                    'recovery_request' => true,
                    'module_entitlements' => false,
                ],
            ],
        ]);
    }

    public function recoveryRequest(Request $request, LicenseAuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'requested_duration_days' => ['required', 'integer', 'min:1', 'max:7'],
        ]);

        $package = $this->recoveryRequestService->generate(
            $validated['reason'],
            (int) $validated['requested_duration_days']
        );

        $auditLogger->log(
            'EMERGENCY_RECOVERY_REQUESTED',
            $package['license_reason_code'] ?? null,
            [
                'license_id' => $package['license_id'] ?? null,
                'client_id' => $package['client_id'] ?? null,
                'deployment_id' => $package['current_deployment_id'] ?? null,
                'location_code' => $package['licensed_location_code'] ?? null,
                'current_fingerprint_hash' => $package['current_fingerprint_hash'] ?? null,
                'metadata' => [
                    'requested_duration_days' => $package['requested_duration_days'],
                    'reason' => $package['reason'],
                    'generated_at' => $package['generated_at'],
                ],
            ],
            $request
        );

        return new JsonResponse([
            'success' => true,
            'data' => $package,
        ]);
    }

    public function upload(
        Request $request,
        LicenseReplacementService $replacementService,
        LicenseAuditLogger $auditLogger,
    ): JsonResponse {
        $validated = $request->validate([
            'license_file' => ['required', 'file', 'max:64'],
        ]);

        $file = $validated['license_file'];
        if (strtolower($file->getClientOriginalExtension()) !== 'json') {
            return new JsonResponse([
                'success' => false,
                'error_code' => 'LICENSE_SCHEMA_INVALID',
                'message' => 'The uploaded license must be a JSON file.',
            ], 422);
        }

        $result = $replacementService->validateAndReplace($file->getRealPath());

        $auditLogger->log(
            $result->replaced ? 'LICENSE_REPLACED' : 'LICENSE_REPLACEMENT_REJECTED',
            $result->reasonCode,
            array_merge($result->license?->safeSummary() ?? [], [
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]),
            $request
        );

        if (!$result->replaced) {
            return new JsonResponse([
                'success' => false,
                'error_code' => $result->reasonCode->value,
                'message' => 'The uploaded license could not be validated.',
            ], 422);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $result->safePayload(),
        ]);
    }

    private function safeStatusPayload(array $status): array
    {
        return [
            'license_valid' => (bool) ($status['license_valid'] ?? false),
            'reason_code' => $status['reason_code'] ?? 'LICENSE_VALIDATION_EXCEPTION',
            'deployment_id' => $status['deployment_id'] ?? null,
            'licensed_locations' => $status['licensed_locations'] ?? [],
            'expires_at' => $status['expires_at'] ?? null,
            'environment' => $status['environment'] ?? null,
        ];
    }
}
