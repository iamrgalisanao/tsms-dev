<?php

namespace Tests\Unit;

use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\SignedLicenseReader;
use PHPUnit\Framework\TestCase;

class SignedLicenseReaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/tsms-license-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tempDir);

        parent::tearDown();
    }

    public function test_valid_ed25519_license_is_read_successfully(): void
    {
        $reader = new SignedLicenseReader();
        [$licensePath, $publicKeyPath] = $this->writeSignedLicense([
            'license_id' => 'LIC-MWM-PITX-001',
            'expires_at' => '2027-06-01T00:00:00Z',
        ], $reader);

        $result = $reader->read($licensePath, $publicKeyPath);

        $this->assertTrue($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseValid, $result->reasonCode);
        $this->assertSame('LIC-MWM-PITX-001', $result->license?->licenseId);
        $this->assertSame(['PITX_MANILA'], $result->license?->licensedLocationCodes);
    }

    public function test_tampered_license_fails_signature_verification(): void
    {
        $reader = new SignedLicenseReader();
        [$licensePath, $publicKeyPath] = $this->writeSignedLicense([], $reader);

        $payload = json_decode((string) file_get_contents($licensePath), true, 512, JSON_THROW_ON_ERROR);
        $payload['deployment_id'] = 'MWM-LAGUNA-PROD-001';
        file_put_contents($licensePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $result = $reader->read($licensePath, $publicKeyPath);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseSignatureInvalid, $result->reasonCode);
    }

    public function test_missing_license_returns_missing_reason_code(): void
    {
        $reader = new SignedLicenseReader();

        $result = $reader->read($this->tempDir . '/missing-license.json', $this->tempDir . '/missing-key.pub');

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseFileMissing, $result->reasonCode);
    }

    public function test_invalid_schema_returns_schema_reason_code(): void
    {
        $reader = new SignedLicenseReader();
        $licensePath = $this->tempDir . '/license.json';
        $publicKeyPath = $this->tempDir . '/license_public.key';

        file_put_contents($licensePath, json_encode(['license_id' => 'LIC-MISSING-FIELDS']));
        file_put_contents($publicKeyPath, 'not-used-for-schema-failure');

        $result = $reader->read($licensePath, $publicKeyPath);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseReasonCode::LicenseSchemaInvalid, $result->reasonCode);
        $this->assertArrayHasKey('client_id', $result->errors);
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
            'activation_policy' => [
                'automatic_reactivation_allowed' => false,
                'vendor_approval_required' => true,
                'emergency_recovery_allowed' => true,
            ],
            'signature_algorithm' => 'Ed25519',
        ], $overrides);

        $payload['signature'] = base64_encode(sodium_crypto_sign_detached(
            $reader->canonicalize($payload),
            $secretKey
        ));

        $licensePath = $this->tempDir . '/license.json';
        $publicKeyPath = $this->tempDir . '/license_public.key';

        file_put_contents($licensePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($publicKeyPath, base64_encode($publicKey));

        return [$licensePath, $publicKeyPath];
    }
}
