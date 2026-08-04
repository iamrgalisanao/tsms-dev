<?php

namespace App\Services\Licensing;

use Illuminate\Support\Carbon;
use Throwable;

class LicenseService
{
    private ?LicenseValidationResult $lastResult = null;

    public function __construct(
        private readonly SignedLicenseReader $reader,
        private readonly DeploymentFingerprintService $fingerprintService,
        private readonly ?LicenseAuditLogger $auditLogger = null,
    ) {
    }

    public function validateLicense(bool $refresh = false): LicenseValidationResult
    {
        if (!$refresh && $this->lastResult !== null) {
            return $this->lastResult;
        }

        try {
            $readResult = $this->reader->read();
            if (!$readResult->valid || $readResult->license === null) {
                return $this->lastResult = $this->invalid(
                    $readResult->reasonCode,
                    context: ['reader_errors' => $readResult->errors]
                );
            }

            $license = $readResult->license;
            $now = Carbon::now('UTC');

            if ($license->notBefore !== null && $now->lt(Carbon::parse($license->notBefore)->utc())) {
                return $this->lastResult = $this->invalid(
                    LicenseReasonCode::LicenseNotYetValid,
                    $license
                );
            }

            if ($license->expiresAt !== null && $now->gte(Carbon::parse($license->expiresAt)->utc())) {
                return $this->lastResult = $this->invalid(
                    LicenseReasonCode::LicenseExpired,
                    $license
                );
            }

            $configuredEnvironment = (string) config('license.environment', config('app.env'));
            if (!hash_equals($license->environment, $configuredEnvironment)) {
                return $this->lastResult = $this->invalid(
                    LicenseReasonCode::LicenseEnvironmentMismatch,
                    $license,
                    ['configured_environment' => $configuredEnvironment]
                );
            }

            $configuredDeploymentId = (string) config('license.deployment_id');
            if ($configuredDeploymentId === '' || !hash_equals($license->deploymentId, $configuredDeploymentId)) {
                return $this->lastResult = $this->invalid(
                    LicenseReasonCode::LicenseDeploymentIdMismatch,
                    $license,
                    ['configured_deployment_id' => $configuredDeploymentId]
                );
            }

            $fingerprintAssessment = $this->fingerprintService->assessExpected($license->serverFingerprintHash);
            if (!$fingerprintAssessment->matches) {
                return $this->lastResult = $this->invalid(
                    $fingerprintAssessment->copySuspected
                        ? LicenseReasonCode::CopySuspected
                        : LicenseReasonCode::LicenseServerFingerprintMismatch,
                    $license,
                    $fingerprintAssessment->safeContext()
                );
            }

            return $this->lastResult = LicenseValidationResult::valid($license);
        } catch (Throwable) {
            return $this->lastResult = $this->invalid(
                LicenseReasonCode::LicenseValidationException
            );
        }
    }

    public function isLicenseValid(): bool
    {
        return $this->validateLicense()->valid;
    }

    public function getLicenseStatus(): array
    {
        return $this->validateLicense()->safeStatus();
    }

    public function getFailureReason(): string
    {
        return $this->validateLicense()->reasonCode->value;
    }

    public function getLicensedDeploymentId(): ?string
    {
        return $this->validateLicense()->license?->deploymentId;
    }

    public function getLicensedLocations(): array
    {
        return $this->validateLicense()->license?->licensedLocationCodes ?? [];
    }

    private function invalid(
        LicenseReasonCode $reasonCode,
        ?SignedLicense $license = null,
        array $context = [],
    ): LicenseValidationResult {
        $result = LicenseValidationResult::invalid($reasonCode, $license, $context);

        $this->auditLogger?->log(
            'LICENSE_VALIDATION_FAILED',
            $reasonCode,
            array_merge($license?->safeSummary() ?? [], $context)
        );

        return $result;
    }
}
