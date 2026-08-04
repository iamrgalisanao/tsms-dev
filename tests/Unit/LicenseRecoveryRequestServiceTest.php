<?php

namespace Tests\Unit;

use App\Models\DeploymentMetadata;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseRecoveryRequestService;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class LicenseRecoveryRequestServiceTest extends TestCase
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
                'client_id' => 'MWM',
                'license_id' => 'LIC-MWM-PITX-001',
                'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
                'location_code' => 'PITX_MANILA',
                'environment' => 'production',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_recovery_request_package_contains_vendor_needed_safe_metadata(): void
    {
        $service = new LicenseRecoveryRequestService(
            $this->fingerprintService(),
            $this->licenseService(),
        );

        $package = $service->generate('Server restored from approved PITX backup after storage failure.', 7);

        $this->assertSame('license_recovery_request', $package['request_type']);
        $this->assertSame('MWM', $package['client_id']);
        $this->assertSame('LIC-MWM-PITX-001', $package['license_id']);
        $this->assertSame('MWM-PITX-MANILA-PROD-001', $package['current_deployment_id']);
        $this->assertSame('PITX_MANILA', $package['licensed_location_code']);
        $this->assertSame('current-fingerprint-hash', $package['current_fingerprint_hash']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $package['application_installation_uuid']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $package['database_instance_uuid']);
        $this->assertSame(7, $package['requested_duration_days']);
        $this->assertArrayNotHasKey('hostname', $package);
        $this->assertArrayNotHasKey('mac_address', $package);
        $this->assertArrayNotHasKey('signature', $package);
        $this->assertArrayNotHasKey('token', $package);
    }

    private function fingerprintService(): DeploymentFingerprintService
    {
        return new class extends DeploymentFingerprintService {
            public function currentMetadata(): DeploymentMetadata
            {
                return new DeploymentMetadata([
                    'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
                    'environment' => 'production',
                    'application_installation_uuid' => '11111111-1111-4111-8111-111111111111',
                    'database_instance_uuid' => '22222222-2222-4222-8222-222222222222',
                    'current_fingerprint_hash' => 'current-fingerprint-hash',
                ]);
            }

            public function currentFingerprintHash(): string
            {
                return 'current-fingerprint-hash';
            }
        };
    }

    private function licenseService(): LicenseService
    {
        return new class(
            new SignedLicenseReader(),
            new DeploymentFingerprintService(),
        ) extends LicenseService {
            public function getLicenseStatus(): array
            {
                return [
                    'license_valid' => false,
                    'reason_code' => 'COPY_SUSPECTED',
                    'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
                    'licensed_locations' => ['PITX_MANILA'],
                    'expires_at' => '2027-06-01T00:00:00Z',
                    'environment' => 'production',
                ];
            }
        };
    }
}
