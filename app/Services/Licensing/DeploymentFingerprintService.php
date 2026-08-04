<?php

namespace App\Services\Licensing;

use App\Models\DeploymentMetadata;
use Illuminate\Support\Str;

class DeploymentFingerprintService
{
    public function currentMetadata(): DeploymentMetadata
    {
        $deploymentId = $this->configuredDeploymentId();
        $environment = $this->configuredEnvironment();

        $metadata = DeploymentMetadata::firstOrCreate(
            [
                'deployment_id' => $deploymentId,
                'environment' => $environment,
            ],
            [
                'license_id' => config('license.license_id'),
                'client_id' => config('license.client_id'),
                'application_installation_uuid' => (string) Str::uuid(),
                'database_instance_uuid' => (string) Str::uuid(),
                'status' => 'pending',
            ]
        );

        $fingerprint = $this->generateFingerprintHash($metadata);

        if ($metadata->current_fingerprint_hash !== $fingerprint) {
            $metadata->forceFill([
                'current_fingerprint_hash' => $fingerprint,
            ])->save();
        }

        return $metadata->refresh();
    }

    public function currentFingerprintHash(): string
    {
        return (string) $this->currentMetadata()->current_fingerprint_hash;
    }

    public function generateFingerprintHash(?DeploymentMetadata $metadata = null): string
    {
        $metadata ??= $this->currentMetadata();

        return hash('sha256', $this->canonicalizeFingerprintInputs($this->hardInputs($metadata)));
    }

    public function matchesExpected(?string $expectedFingerprintHash): bool
    {
        return $this->assessExpected($expectedFingerprintHash)->matches;
    }

    public function assessExpected(?string $expectedFingerprintHash): DeploymentFingerprintAssessment
    {
        $metadata = $this->currentMetadata();

        return $this->assessMetadataAgainstExpected($metadata, $expectedFingerprintHash);
    }

    public function assessMetadataAgainstExpected(
        DeploymentMetadata $metadata,
        ?string $expectedFingerprintHash,
    ): DeploymentFingerprintAssessment {
        $currentFingerprintHash = $this->generateFingerprintHash($metadata);
        $expectedFingerprintHash = $expectedFingerprintHash !== null && trim($expectedFingerprintHash) !== ''
            ? $expectedFingerprintHash
            : null;

        $matches = $expectedFingerprintHash !== null
            && hash_equals($expectedFingerprintHash, $currentFingerprintHash);

        return new DeploymentFingerprintAssessment(
            matches: $matches,
            copySuspected: !$matches
                && $expectedFingerprintHash !== null
                && $this->hasStableDeploymentIdentity($metadata),
            currentFingerprintHash: $currentFingerprintHash,
            expectedFingerprintHash: $expectedFingerprintHash,
            softDiagnostics: $this->softDiagnostics(),
        );
    }

    public function hardInputs(DeploymentMetadata $metadata): array
    {
        return [
            'deployment_id' => $metadata->deployment_id,
            'application_installation_uuid' => $metadata->application_installation_uuid,
            'database_instance_uuid' => $metadata->database_instance_uuid,
            'environment' => $metadata->environment,
            'approved_server_identifier' => config('license.fingerprint.approved_server_identifier'),
        ];
    }

    public function softDiagnostics(): array
    {
        return [
            'hostname_hash' => $this->hashNullable(gethostname() ?: null),
            'server_addr_hash' => $this->hashNullable($_SERVER['SERVER_ADDR'] ?? null),
            'remote_addr_hash' => $this->hashNullable($_SERVER['REMOTE_ADDR'] ?? null),
        ];
    }

    private function configuredDeploymentId(): string
    {
        $deploymentId = (string) config('license.deployment_id');

        return trim($deploymentId) !== '' ? $deploymentId : 'UNCONFIGURED-DEPLOYMENT';
    }

    private function configuredEnvironment(): string
    {
        $environment = (string) config('license.environment', config('app.env', 'production'));

        return trim($environment) !== '' ? $environment : 'production';
    }

    private function canonicalizeFingerprintInputs(array $inputs): string
    {
        ksort($inputs);

        return json_encode($inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function hashNullable(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash('sha256', $value);
    }

    private function hasStableDeploymentIdentity(DeploymentMetadata $metadata): bool
    {
        return trim((string) $metadata->application_installation_uuid) !== ''
            && trim((string) $metadata->database_instance_uuid) !== '';
    }
}
