<?php

namespace App\Services\Licensing;

final readonly class LicenseReplacementResult
{
    private function __construct(
        public bool $replaced,
        public LicenseReasonCode $reasonCode,
        public ?SignedLicense $license = null,
        public ?string $targetPath = null,
    ) {
    }

    public static function replaced(SignedLicense $license, string $targetPath): self
    {
        return new self(true, LicenseReasonCode::LicenseValid, $license, $targetPath);
    }

    public static function rejected(LicenseReasonCode $reasonCode): self
    {
        return new self(false, $reasonCode);
    }

    public function safePayload(): array
    {
        return [
            'replaced' => $this->replaced,
            'reason_code' => $this->reasonCode->value,
            'license' => $this->license?->safeSummary(),
        ];
    }
}
