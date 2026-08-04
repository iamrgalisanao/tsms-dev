<?php

namespace Tests\Unit;

use App\Models\DeploymentMetadata;
use App\Services\Licensing\DeploymentFingerprintService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class DeploymentFingerprintServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'license' => [
                'fingerprint' => [
                    'approved_server_identifier' => 'server-a',
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_stable_metadata_generates_matching_fingerprint_assessment(): void
    {
        $service = new DeploymentFingerprintService();
        $metadata = $this->metadata();
        $expected = $service->generateFingerprintHash($metadata);

        $assessment = $service->assessMetadataAgainstExpected($metadata, $expected);

        $this->assertTrue($assessment->matches);
        $this->assertFalse($assessment->copySuspected);
        $this->assertSame($expected, $assessment->currentFingerprintHash);
    }

    public function test_mismatched_expected_hash_with_stable_identity_is_copy_suspected(): void
    {
        $service = new DeploymentFingerprintService();
        $metadata = $this->metadata();

        $assessment = $service->assessMetadataAgainstExpected($metadata, str_repeat('a', 64));

        $this->assertFalse($assessment->matches);
        $this->assertTrue($assessment->copySuspected);
        $this->assertSame(str_repeat('a', 64), $assessment->expectedFingerprintHash);
    }

    private function metadata(): DeploymentMetadata
    {
        return new DeploymentMetadata([
            'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
            'environment' => 'production',
            'application_installation_uuid' => '11111111-1111-4111-8111-111111111111',
            'database_instance_uuid' => '22222222-2222-4222-8222-222222222222',
        ]);
    }
}
