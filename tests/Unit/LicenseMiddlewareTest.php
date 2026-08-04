<?php

namespace Tests\Unit;

use App\Http\Middleware\LicenseMiddleware;
use App\Models\LicenseAuditLog;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseAuditLogger;
use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\LicenseValidationResult;
use App\Services\Licensing\SignedLicense;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class LicenseMiddlewareTest extends TestCase
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
                'allowed_enforcement_modes' => ['disabled', 'observe', 'restricted', 'enforce'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_disabled_mode_bypasses_license_validation(): void
    {
        config(['license.enforcement_mode' => 'disabled']);
        $logger = new CapturingLicenseAuditLogger();
        $middleware = new LicenseMiddleware(
            new FakeLicenseService(LicenseValidationResult::invalid(LicenseReasonCode::LicenseExpired)),
            $logger,
        );

        $response = $middleware->handle($this->request(), fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $logger->events);
    }

    public function test_observe_mode_audits_invalid_license_but_allows_request(): void
    {
        config(['license.enforcement_mode' => 'observe']);
        $logger = new CapturingLicenseAuditLogger();
        $middleware = new LicenseMiddleware(
            new FakeLicenseService(LicenseValidationResult::invalid(LicenseReasonCode::LicenseExpired, $this->license())),
            $logger,
        );

        $response = $middleware->handle($this->request(), fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('LICENSE_OBSERVED_VIOLATION', $logger->events[0]['event_type']);
        $this->assertSame('LICENSE_EXPIRED', $logger->events[0]['reason_code']);
    }

    public function test_enforce_mode_blocks_invalid_license_with_safe_response(): void
    {
        config(['license.enforcement_mode' => 'enforce']);
        $logger = new CapturingLicenseAuditLogger();
        $middleware = new LicenseMiddleware(
            new FakeLicenseService(LicenseValidationResult::invalid(LicenseReasonCode::LicenseExpired, $this->license())),
            $logger,
        );
        $request = $this->request();
        $request->attributes->set('correlation_id', 'trace-123');

        $response = $middleware->handle($request, fn () => new Response('should-not-run', 200));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertSame('LICENSE_EXPIRED', $payload['error_code']);
        $this->assertSame('trace-123', $payload['trace_id']);
        $this->assertArrayNotHasKey('current_fingerprint_hash', $payload);
        $this->assertSame('LICENSE_ENFORCEMENT_DENIED', $logger->events[0]['event_type']);
    }

    private function request(): Request
    {
        return Request::create('/api/v1/transactions/official', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
    }

    private function license(): SignedLicense
    {
        return SignedLicense::fromPayload([
            'license_version' => '1.0',
            'license_id' => 'LIC-MWM-PITX-001',
            'client_id' => 'MWM',
            'environment' => 'production',
            'deployment_id' => 'MWM-PITX-MANILA-PROD-001',
            'not_before' => '2026-06-01T00:00:00Z',
            'expires_at' => '2026-06-02T00:00:00Z',
            'licensed_location_codes' => ['PITX_MANILA'],
            'server_fingerprint_hash' => 'expected-fingerprint-hash',
            'signature_algorithm' => 'Ed25519',
            'signature' => 'redacted-in-tests',
        ]);
    }
}

class FakeLicenseService extends LicenseService
{
    public function __construct(private readonly LicenseValidationResult $result)
    {
        parent::__construct(new SignedLicenseReader(), new DeploymentFingerprintService());
    }

    public function validateLicense(bool $refresh = false): LicenseValidationResult
    {
        return $this->result;
    }
}

class CapturingLicenseAuditLogger extends LicenseAuditLogger
{
    public array $events = [];

    public function log(
        string $eventType,
        LicenseReasonCode|string|null $reasonCode = null,
        array $context = [],
        ?Request $request = null,
    ): LicenseAuditLog {
        $this->events[] = [
            'event_type' => $eventType,
            'reason_code' => $reasonCode instanceof LicenseReasonCode ? $reasonCode->value : $reasonCode,
            'context' => $this->sanitize($context),
        ];

        return new LicenseAuditLog();
    }
}
