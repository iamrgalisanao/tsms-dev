<?php

namespace Tests\Unit;

use App\Http\Controllers\API\V1\LicenseController;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseRecoveryRequestService;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class LicenseControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'license' => [
                'enabled' => true,
                'enforcement_mode' => 'observe',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_status_response_exposes_safe_license_diagnostics(): void
    {
        $controller = new LicenseController($this->licenseService(), $this->recoveryService());

        $payload = $controller->status()->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['license_valid']);
        $this->assertSame('LICENSE_VALID', $payload['data']['reason_code']);
        $this->assertSame('observe', $payload['data']['enforcement_mode']);
        $this->assertArrayNotHasKey('context', $payload['data']);
        $this->assertArrayNotHasKey('current_fingerprint_hash', $payload['data']);
        $this->assertArrayNotHasKey('expected_fingerprint_hash', $payload['data']);
    }

    public function test_capabilities_response_marks_release_two_entitlements_unavailable(): void
    {
        $controller = new LicenseController($this->licenseService(), $this->recoveryService());

        $payload = $controller->capabilities()->getData(true);

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['capabilities']['redeployment_control']);
        $this->assertFalse($payload['data']['capabilities']['module_entitlements']);
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
                    'license_valid' => true,
                    'reason_code' => 'LICENSE_VALID',
                    'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
                    'licensed_locations' => ['PITX_MANILA'],
                    'expires_at' => '2027-06-01T00:00:00Z',
                    'environment' => 'production',
                    'context' => [
                        'current_fingerprint_hash' => 'should-not-leak',
                    ],
                ];
            }
        };
    }

    private function recoveryService(): LicenseRecoveryRequestService
    {
        return new LicenseRecoveryRequestService(
            new DeploymentFingerprintService(),
            $this->licenseService(),
        );
    }
}
