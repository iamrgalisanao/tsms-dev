<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\LicenseValidationResult;
use App\Services\Licensing\SignedLicenseReader;
use App\Services\PayloadChecksumService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retargeted from the retired /api/v1/transactions/batch route to
 * /api/v1/transactions/official (003-ingestion-hardening US1); the
 * license.valid middleware assertions are unchanged.
 */
class PosIngestionLicenseEnforcementTest extends TestCase
{
    #[DataProvider('passThroughModes')]
    public function test_official_ingestion_passes_through_when_license_mode_does_not_block(string $mode): void
    {
        Queue::fake();
        $this->useInvalidLicenseInMode($mode);

        [, $terminal] = $this->seedTerminal();
        $token = $terminal->generateAccessToken();
        $payload = $this->officialPayload($terminal);

        $this->postJson('/api/v1/transactions/official', $payload, [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(200)->assertJsonPath('code', 'ACCEPTED');

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
        ]);
    }

    #[DataProvider('blockingModes')]
    public function test_official_ingestion_is_blocked_when_license_mode_enforces_invalid_license(string $mode): void
    {
        Queue::fake();
        $this->useInvalidLicenseInMode($mode);

        [, $terminal] = $this->seedTerminal();
        $token = $terminal->generateAccessToken();
        $payload = $this->officialPayload($terminal);

        $this->postJson('/api/v1/transactions/official', $payload, [
            'Authorization' => 'Bearer ' . $token,
            'X-Request-Id' => 'license-regression-trace',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', LicenseReasonCode::LicenseExpired->value)
            ->assertJsonPath('trace_id', 'license-regression-trace');

        $this->assertDatabaseMissing('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
        ]);
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transaction']['transaction_id'],
        ]);
        Queue::assertNothingPushed();
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

    private function officialPayload(PosTerminal $terminal): array
    {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'LIC-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-LICENSE-TEST',
            'adjustments' => array_map(
                fn (string $type) => ['adjustment_type' => $type, 'amount' => 0],
                [
                    'promo_discount',
                    'senior_discount',
                    'pwd_discount',
                    'vip_card_discount',
                    'service_charge_distributed_to_employees',
                    'service_charge_retained_by_management',
                    'employee_discount',
                ]
            ),
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0],
            ],
        ];
        $transaction['payload_checksum'] = $service->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'submission_timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
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
