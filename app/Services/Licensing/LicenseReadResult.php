<?php

namespace App\Services\Licensing;

final readonly class LicenseReadResult
{
    private function __construct(
        public bool $valid,
        public LicenseReasonCode $reasonCode,
        public ?SignedLicense $license = null,
        public array $errors = [],
    ) {
    }

    public static function valid(SignedLicense $license): self
    {
        return new self(true, LicenseReasonCode::LicenseValid, $license);
    }

    public static function invalid(LicenseReasonCode $reasonCode, array $errors = []): self
    {
        return new self(false, $reasonCode, null, $errors);
    }

    public function safeStatus(): array
    {
        return [
            'valid' => $this->valid,
            'reason_code' => $this->reasonCode->value,
            'license' => $this->license?->safeSummary(),
            'errors' => $this->errors,
        ];
    }
}
