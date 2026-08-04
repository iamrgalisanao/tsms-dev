<?php

namespace Tests\Unit;

use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\DeploymentFingerprintAssessment;
use App\Services\Licensing\LicenseReadResult;
use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\SignedLicense;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class LicenseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'app' => [
                'env' => 'testing',
            ],
            'license' => [
                'environment' => 'production',
                'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_valid_license_passes_core_validation(): void
    {
        $service = $this->serviceFor($this->license(), fingerprintMatches: true);

        $result = $service->validateLicense();

        $this->assertTrue($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseValid, $result->reasonCode);
    }

    public function test_expired_license_fails_core_validation(): void
    {
        $service = $this->serviceFor($this->license([
            'expires_at' => '2026-01-01T00:00:00Z',
        ]), fingerprintMatches: true);

        $result = $service->validateLicense();

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseExpired, $result->reasonCode);
    }

    public function test_environment_mismatch_fails_core_validation(): void
    {
        $service = $this->serviceFor($this->license([
            'environment' => 'staging',
        ]), fingerprintMatches: true);

        $result = $service->validateLicense();

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseEnvironmentMismatch, $result->reasonCode);
    }

    public function test_deployment_mismatch_fails_core_validation(): void
    {
        $service = $this->serviceFor($this->license([
            'deployment_id' => 'MWM-LAGUNA-PROD-001',
        ]), fingerprintMatches: true);

        $result = $service->validateLicense();

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseDeploymentIdMismatch, $result->reasonCode);
    }

    public function test_fingerprint_mismatch_fails_core_validation(): void
    {
        $service = $this->serviceFor($this->license(), fingerprintMatches: false);

        $result = $service->validateLicense();

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseServerFingerprintMismatch, $result->reasonCode);
    }

    public function test_copy_suspected_is_returned_when_fingerprint_assessment_flags_copy(): void
    {
        $service = $this->serviceFor($this->license(), fingerprintMatches: false, copySuspected: true);

        $result = $service->validateLicense();

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::CopySuspected, $result->reasonCode);
        $this->assertTrue($result->context['copy_suspected']);
    }

    private function serviceFor(
        SignedLicense $license,
        bool $fingerprintMatches,
        bool $copySuspected = false,
    ): LicenseService
    {
        $reader = new class($license) extends SignedLicenseReader {
            public function __construct(private readonly SignedLicense $license)
            {
            }

            public function read(?string $licensePath = null, ?string $publicKeyPath = null): LicenseReadResult
            {
                return LicenseReadResult::valid($this->license);
            }
        };

        $fingerprint = new class($fingerprintMatches, $copySuspected) extends DeploymentFingerprintService {
            public function __construct(
                private readonly bool $matches,
                private readonly bool $copySuspected,
            )
            {
            }

            public function matchesExpected(?string $expectedFingerprintHash): bool
            {
                return $this->matches;
            }

            public function assessExpected(?string $expectedFingerprintHash): DeploymentFingerprintAssessment
            {
                return new DeploymentFingerprintAssessment(
                    matches: $this->matches,
                    copySuspected: $this->copySuspected,
                    currentFingerprintHash: 'current-fingerprint-hash',
                    expectedFingerprintHash: $expectedFingerprintHash,
                );
            }
        };

        return new LicenseService($reader, $fingerprint);
    }

    private function license(array $overrides = []): SignedLicense
    {
        return SignedLicense::fromPayload(array_replace([
            'license_version' => '1.0',
            'license_id' => 'LIC-MWM-PITX-001',
            'client_id' => 'MWM',
            'environment' => 'production',
            'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
            'not_before' => '2026-06-01T00:00:00Z',
            'expires_at' => '2027-06-01T00:00:00Z',
            'licensed_location_codes' => ['PITX_MANILA'],
            'server_fingerprint_hash' => 'expected-fingerprint-hash',
            'signature_algorithm' => 'Ed25519',
            'signature' => 'unused-in-service-test',
        ], $overrides));
    }
}
