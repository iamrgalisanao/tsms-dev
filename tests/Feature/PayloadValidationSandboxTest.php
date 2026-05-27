<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayloadValidationSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_payload_returns_diagnostic_success(): void
    {
        $this->seedTerminal(16, 97);

        $response = $this->postJson('/api/v1/sandbox/payload/validate', $this->validPayload());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'valid' => true,
                'summary' => [
                    'error_count' => 0,
                ],
                'checks' => [
                    'schema' => 'passed',
                    'checksum' => 'passed',
                    'contract' => 'passed',
                    'business_rules' => 'passed',
                ],
                'checksums' => [
                    'transaction' => [
                        'matches' => true,
                    ],
                    'submission' => [
                        'matches' => true,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'validation_id',
                'errors',
                'warnings',
            ]);
    }

    public function test_invalid_payload_returns_actionable_diagnostics(): void
    {
        $this->seedTerminal(16, 97);

        $response = $this->postJson('/api/v1/sandbox/payload/validate', $this->invalidPayload());

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'valid' => false,
                'checks' => [
                    'checksum' => 'failed',
                    'contract' => 'failed',
                    'business_rules' => 'failed',
                ],
                'checksums' => [
                    'transaction' => [
                        'provided' => 'e0736715ef3e6d86aa176c455908df6b0b1d95169aa8b1a944c9d26d569804da',
                        'computed' => '2199a619026a1a7137fc17fec2a31f6e6a954368c6593c798a0ed4b120010733',
                        'matches' => false,
                    ],
                    'submission' => [
                        'provided' => 'c7ec5ef62a44dca24638944f9dc7402da0d3dde697d510a3c04f4e7a1560c475',
                        'computed' => '7c17217b4d3350044349cb460bd50f99f5b632170ac08fdaf02f37ac78c11f00',
                        'matches' => false,
                    ],
                ],
            ]);

        $codes = collect($response->json('errors'))->pluck('code')->all();
        $this->assertContains('HARDWARE_ID_MISSING_IN_TRANSACTION', $codes);
        $this->assertContains('TRANSACTION_CHECKSUM_MISMATCH', $codes);
        $this->assertContains('SUBMISSION_CHECKSUM_MISMATCH', $codes);
        $this->assertContains('VAT_RECONCILIATION_FAILED', $codes);

        $warningCodes = collect($response->json('warnings'))->pluck('code')->all();
        $this->assertContains('HARDWARE_ID_AT_ROOT_ONLY', $warningCodes);
        $this->assertContains('CHECKSUM_CASCADE', $warningCodes);
    }

    public function test_include_debug_returns_canonical_json(): void
    {
        $this->seedTerminal(16, 97);

        $response = $this->postJson('/api/v1/sandbox/payload/validate?include_debug=true', $this->validPayload());

        $response->assertOk()
            ->assertJsonPath('debug.canonical_transaction_json', '{"adjustments":[{"adjustment_type":"promo_discount","amount":"0.00"},{"adjustment_type":"senior_discount","amount":"0.00"},{"adjustment_type":"pwd_discount","amount":"0.00"},{"adjustment_type":"vip_card_discount","amount":"0.00"},{"adjustment_type":"service_charge_distributed_to_employees","amount":"0.00"},{"adjustment_type":"service_charge_retained_by_management","amount":"0.00"},{"adjustment_type":"employee_discount","amount":"0.00"}],"customer_code":"C-B1028","gross_sales":"140.00","hardware_id":"BUI-XTM80213","net_sales":"125.00","promo_status":"WITHOUT_APPROVAL","receipt_no":"000001072840","taxes":[{"amount":"15.00","tax_type":"VAT"},{"amount":"125.00","tax_type":"VATABLE_SALES"},{"amount":"0.00","tax_type":"SC_VAT_EXEMPT_SALES"},{"amount":"0.00","tax_type":"OTHER_TAX"}],"transaction_id":"f9c5dd6a-2d71-40b8-903c-df0a02b417ed","transaction_timestamp":"2026-05-14T08:59:28Z"}');
    }

    public function test_malformed_json_returns_bad_request(): void
    {
        $response = $this
            ->withHeader('Content-Type', 'application/json')
            ->post('/api/v1/sandbox/payload/validate', ['{"submission_uuid":']);

        $response->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'INVALID_JSON');
    }

    public function test_transaction_id_must_be_uuid_but_can_differ_from_submission_uuid(): void
    {
        $this->seedTerminal(16, 97);

        $payload = $this->validPayload();
        $this->assertNotSame($payload['submission_uuid'], $payload['transaction']['transaction_id']);

        $payload['transaction']['transaction_id'] = 'POS-TXN-000001';

        $response = $this->postJson('/api/v1/sandbox/payload/validate', $payload);

        $response->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('checks.schema', 'failed');

        $errors = collect($response->json('errors'));
        $this->assertTrue(
            $errors->contains(fn (array $error) => ($error['code'] ?? null) === 'INVALID_UUID_FORMAT'
                && ($error['pointer'] ?? null) === '/transaction/transaction_id'),
            'Sandbox should report transaction.transaction_id as an invalid UUID.'
        );
    }

    private function seedTerminal(int $tenantId, int $terminalId): PosTerminal
    {
        $tenant = Tenant::find($tenantId) ?? Tenant::factory()->create(['id' => $tenantId]);

        PosTerminal::whereKey($terminalId)->delete();

        return PosTerminal::factory()->create([
            'id' => $terminalId,
            'tenant_id' => $tenant->id,
            'status_id' => 1,
        ]);
    }

    private function validPayload(): array
    {
        return [
            'submission_uuid' => 'f8c1a35a-90db-41ab-8c05-1b27cc7a40a9',
            'tenant_id' => 16,
            'terminal_id' => 97,
            'submission_timestamp' => '2026-05-14T08:59:28Z',
            'transaction_count' => 1,
            'transaction' => [
                'hardware_id' => 'BUI-XTM80213',
                'receipt_no' => '000001072840',
                'transaction_id' => 'f9c5dd6a-2d71-40b8-903c-df0a02b417ed',
                'transaction_timestamp' => '2026-05-14T08:59:28Z',
                'gross_sales' => '140.00',
                'net_sales' => '125.00',
                'promo_status' => 'WITHOUT_APPROVAL',
                'customer_code' => 'C-B1028',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
                    ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
                    ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => '15.00'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '125.00'],
                    ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                    ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
                ],
                'payload_checksum' => 'afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f',
            ],
            'payload_checksum' => '30cf6eeb6513ca6f31e9d091e7bbd8a519f5d3264a128cc715fee6d61b684154',
        ];
    }

    private function invalidPayload(): array
    {
        return [
            'submission_uuid' => '74491558-7796-468c-931d-e937a0812305',
            'tenant_id' => 16,
            'terminal_id' => 97,
            'submission_timestamp' => '2026-05-14T08:59:28Z',
            'transaction_count' => 1,
            'hardware_id' => 'BUI-XTM80213',
            'transaction' => [
                'receipt_no' => '000001072840',
                'transaction_id' => '295f39c8-b045-40c1-adb6-439294449bb7',
                'transaction_timestamp' => '2026-05-14T08:59:28Z',
                'gross_sales' => '140.00',
                'net_sales' => '125.00',
                'promo_status' => 'WITHOUT_APPROVAL',
                'customer_code' => 'C-B1028',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
                    ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
                    ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => '15.00'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '0.00'],
                    ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                    ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
                ],
                'payload_checksum' => 'e0736715ef3e6d86aa176c455908df6b0b1d95169aa8b1a944c9d26d569804da',
            ],
            'payload_checksum' => 'c7ec5ef62a44dca24638944f9dc7402da0d3dde697d510a3c04f4e7a1560c475',
        ];
    }
}
