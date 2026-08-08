<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\LicenseValidationResult;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosIngestionLicenseEnforcementTest extends TestCase
{
    #[DataProvider('passThroughModes')]
    public function test_batch_ingestion_passes_through_when_license_mode_does_not_block(string $mode): void
    {
        $this->useInvalidLicenseInMode($mode);

        [, $terminal] = $this->seedTerminal();
        $token = $terminal->generateAccessToken();
        $payload = $this->batchPayload($terminal);

        $this->postJson('/api/v1/transactions/batch', $payload, [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
        ]);
    }

    #[DataProvider('blockingModes')]
    public function test_batch_ingestion_is_blocked_when_license_mode_enforces_invalid_license(string $mode): void
    {
        $this->useInvalidLicenseInMode($mode);

        [, $terminal] = $this->seedTerminal();
        $token = $terminal->generateAccessToken();
        $payload = $this->batchPayload($terminal);

        $this->postJson('/api/v1/transactions/batch', $payload, [
            'Authorization' => 'Bearer ' . $token,
            'X-Request-Id' => 'license-regression-trace',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', LicenseReasonCode::LicenseExpired->value)
            ->assertJsonPath('trace_id', 'license-regression-trace');

        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
        ]);
    }

    public static function passThroughModes(): array
    {
        return [
            'disabled' => ['disabled'],
            'observe' => ['observe'],
        ];
    }

    public static function blockingModes(): array
    {
        return [
            'restricted' => ['restricted'],
            'enforce' => ['enforce'],
        ];
    }

    private function useInvalidLicenseInMode(string $mode): void
    {
        config([
            'license.enabled' => true,
            'license.enforcement_mode' => $mode,
        ]);

        $this->app->instance(LicenseService::class, new InvalidPosIngestionLicenseService());
    }

    private function seedTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function batchPayload(PosTerminal $terminal): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-LICENSE-TEST',
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => (string) Str::uuid(),
                    'hardware_id' => 'HW-LICENSE-TEST',
                    'gross_sales' => 100.0,
                    'net_sales' => 100.0,
                    'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                    'payload_checksum' => hash('sha256', Str::uuid()->toString()),
                    'items' => [
                        ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                    ],
                ],
            ],
        ];
    }
}

class InvalidPosIngestionLicenseService extends LicenseService
{
    public function __construct()
    {
        parent::__construct(new SignedLicenseReader(), new DeploymentFingerprintService());
    }

    public function validateLicense(bool $refresh = false): LicenseValidationResult
    {
        return LicenseValidationResult::invalid(LicenseReasonCode::LicenseExpired);
    }
}
