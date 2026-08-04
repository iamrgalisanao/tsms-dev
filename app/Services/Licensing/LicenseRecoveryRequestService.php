<?php

namespace App\Services\Licensing;

use Illuminate\Support\Carbon;

class LicenseRecoveryRequestService
{
    public function __construct(
        private readonly DeploymentFingerprintService $fingerprintService,
        private readonly LicenseService $licenseService,
    ) {
    }

    public function generate(string $reason, int $requestedDurationDays): array
    {
        $metadata = $this->fingerprintService->currentMetadata();
        $licenseStatus = $this->licenseService->getLicenseStatus();

        return [
            'request_type' => 'license_recovery_request',
            'client_id' => config('license.client_id'),
            'license_id' => config('license.license_id'),
            'current_deployment_id' => config('license.deployment_id'),
            'licensed_location_code' => config('license.location_code')
                ?: ($licenseStatus['licensed_locations'][0] ?? null),
            'current_fingerprint_hash' => $this->fingerprintService->currentFingerprintHash(),
            'database_instance_uuid' => $metadata->database_instance_uuid,
            'application_installation_uuid' => $metadata->application_installation_uuid,
            'environment' => config('license.environment', config('app.env')),
            'license_valid' => $licenseStatus['license_valid'] ?? false,
            'license_reason_code' => $licenseStatus['reason_code'] ?? LicenseReasonCode::LicenseValidationException->value,
            'reason' => $reason,
            'requested_duration_days' => $requestedDurationDays,
            'generated_at' => Carbon::now('UTC')->toISOString(),
        ];
    }
}
