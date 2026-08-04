<?php

namespace App\Services\Licensing;

final readonly class SignedLicense
{
    public function __construct(
        public array $payload,
        public string $licenseVersion,
        public string $licenseId,
        public string $clientId,
        public string $environment,
        public string $deploymentId,
        public array $licensedLocationCodes,
        public string $signatureAlgorithm,
        public string $signature,
        public ?string $notBefore,
        public ?string $expiresAt,
        public ?string $serverFingerprintHash,
    ) {
    }

    public static function fromPayload(array $payload): self
    {
        return new self(
            payload: $payload,
            licenseVersion: (string) $payload['license_version'],
            licenseId: (string) $payload['license_id'],
            clientId: (string) $payload['client_id'],
            environment: (string) $payload['environment'],
            deploymentId: (string) $payload['deployment_id'],
            licensedLocationCodes: array_values($payload['licensed_location_codes']),
            signatureAlgorithm: (string) $payload['signature_algorithm'],
            signature: (string) $payload['signature'],
            notBefore: isset($payload['not_before']) ? (string) $payload['not_before'] : null,
            expiresAt: isset($payload['expires_at']) ? (string) $payload['expires_at'] : null,
            serverFingerprintHash: isset($payload['server_fingerprint_hash'])
                ? (string) $payload['server_fingerprint_hash']
                : null,
        );
    }

    public function unsignedPayload(): array
    {
        $payload = $this->payload;
        unset($payload['signature']);

        return $payload;
    }

    public function safeSummary(): array
    {
        return [
            'license_version' => $this->licenseVersion,
            'license_id' => $this->licenseId,
            'client_id' => $this->clientId,
            'environment' => $this->environment,
            'deployment_id' => $this->deploymentId,
            'licensed_location_codes' => $this->licensedLocationCodes,
            'not_before' => $this->notBefore,
            'expires_at' => $this->expiresAt,
            'signature_algorithm' => $this->signatureAlgorithm,
        ];
    }
}
