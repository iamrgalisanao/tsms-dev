<?php

namespace Tests\Unit;

use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseReplacementService;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class LicenseReplacementServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/tsms-license-replace-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);

        $container = new Container();
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_valid_candidate_license_replaces_active_license_atomically(): void
    {
        $reader = new SignedLicenseReader();
        [$candidatePath, $publicKeyPath] = $this->writeSignedLicense(['license_id' => 'LIC-NEW'], $reader);
        $activePath = $this->tempDir . '/private/license.json';
        mkdir(dirname($activePath), 0700, true);
        file_put_contents($activePath, '{"old":true}');

        $this->bindConfig($activePath, $publicKeyPath);

        $result = (new LicenseReplacementService($reader))->validateAndReplace($candidatePath);

        $this->assertTrue($result->replaced);
        $this->assertSame(LicenseReasonCode::LicenseValid, $result->reasonCode);
        $this->assertSame('LIC-NEW', $result->license?->licenseId);
        $this->assertStringContainsString('LIC-NEW', (string) file_get_contents($activePath));
    }

    public function test_tampered_candidate_license_is_rejected_without_replacing_active_license(): void
    {
        $reader = new SignedLicenseReader();
        [$candidatePath, $publicKeyPath] = $this->writeSignedLicense([], $reader);
        $payload = json_decode((string) file_get_contents($candidatePath), true, 512, JSON_THROW_ON_ERROR);
        $payload['deployment_id'] = 'MWM-LAGUNA-PROD-001';
        file_put_contents($candidatePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $activePath = $this->tempDir . '/private/license.json';
        mkdir(dirname($activePath), 0700, true);
        file_put_contents($activePath, '{"old":true}');
        $this->bindConfig($activePath, $publicKeyPath);

        $result = (new LicenseReplacementService($reader))->validateAndReplace($candidatePath);

        $this->assertFalse($result->replaced);
        $this->assertSame(LicenseReasonCode::LicenseSignatureInvalid, $result->reasonCode);
        $this->assertSame('{"old":true}', file_get_contents($activePath));
    }

    private function bindConfig(string $activePath, string $publicKeyPath): void
    {
        app()->instance('config', new Repository([
            'license' => [
                'paths' => [
                    'license_file' => $activePath,
                    'public_key' => $publicKeyPath,
                ],
            ],
        ]));
    }

    private function writeSignedLicense(array $overrides, SignedLicenseReader $reader): array
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium extension is required for Ed25519 license tests.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $payload = array_replace_recursive([
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
        ], $overrides);

        $payload['signature'] = base64_encode(sodium_crypto_sign_detached(
            $reader->canonicalize($payload),
            $secretKey
        ));

        $licensePath = $this->tempDir . '/candidate-license.json';
        $publicKeyPath = $this->tempDir . '/license_public.key';

        file_put_contents($licensePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($publicKeyPath, base64_encode($publicKey));

        return [$licensePath, $publicKeyPath];
    }
}
