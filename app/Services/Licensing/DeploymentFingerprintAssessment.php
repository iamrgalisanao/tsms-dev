<?php

namespace App\Services\Licensing;

final readonly class DeploymentFingerprintAssessment
{
    public function __construct(
        public bool $matches,
        public bool $copySuspected,
        public string $currentFingerprintHash,
        public ?string $expectedFingerprintHash,
        public array $softDiagnostics = [],
    ) {
    }

    public function safeContext(): array
    {
        return [
            'copy_suspected' => $this->copySuspected,
            'current_fingerprint_hash' => $this->currentFingerprintHash,
            'expected_fingerprint_hash' => $this->expectedFingerprintHash,
            'soft_diagnostics' => $this->softDiagnostics,
        ];
    }
}
