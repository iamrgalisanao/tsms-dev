<?php

namespace Tests\Unit;

use App\Services\Licensing\LicenseAuditLogger;
use PHPUnit\Framework\TestCase;

class LicenseAuditLoggerTest extends TestCase
{
    public function test_sanitize_redacts_sensitive_nested_context_values(): void
    {
        $logger = new LicenseAuditLogger();

        $sanitized = $logger->sanitize([
            'license_id' => 'LIC-MWM-PITX-001',
            'terminal_token' => 'plain-token',
            'nested' => [
                'db_password' => 'secret-password',
                'safe_value' => 'visible',
                'signature' => 'base64-signature',
            ],
        ]);

        $this->assertSame('LIC-MWM-PITX-001', $sanitized['license_id']);
        $this->assertSame('[redacted]', $sanitized['terminal_token']);
        $this->assertSame('[redacted]', $sanitized['nested']['db_password']);
        $this->assertSame('[redacted]', $sanitized['nested']['signature']);
        $this->assertSame('visible', $sanitized['nested']['safe_value']);
    }
}
