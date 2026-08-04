<?php

namespace App\Services\Licensing;

final readonly class LicenseValidationResult
{
    private function __construct(
        public bool $valid,
        public LicenseReasonCode $reasonCode,
        public ?SignedLicense $license = null,
        public array $context = [],
    ) {
    }

    public static function valid(SignedLicense $license, array $context = []): self
    {
        return new self(true, LicenseReasonCode::LicenseValid, $license, $context);
    }

    public static function invalid(
        LicenseReasonCode $reasonCode,
        ?SignedLicense $license = null,
        array $context = [],
    ): self {
        return new self(false, $reasonCode, $license, $context);
    }

    public function safeStatus(): array
    {
        return [
            'license_valid' => $this->valid,
            'reason_code' => $this->reasonCode->value,
            'deployment_id' => $this->license?->deploymentId,
            'licensed_locations' => $this->license?->licensedLocationCodes ?? [],
            'expires_at' => $this->license?->expiresAt,
            'environment' => $this->license?->environment,
        ];
    }
}
