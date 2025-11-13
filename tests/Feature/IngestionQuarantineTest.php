<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Company;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestionQuarantineTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $company;
    protected $token;
    protected $checksumService;

    public function setUp(): void
    {
        parent::setUp();

        $this->checksumService = new PayloadChecksumService();

        $this->company = Company::factory()->create([
            'customer_code' => 'CUST-' . Str::upper(Str::random(8))
        ]);

        $tenant = Tenant::factory()->create([
            'company_id' => $this->company->id,
            'trade_name' => 'Quarantine Tenant'
        ]);

        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'serial_number' => 'TERM-' . Str::upper(Str::random(8)),
            'status_id' => 1,
        ]);

        // Generate a token (tests in this repo use a user token as terminal token)
        $user = \App\Models\User::factory()->create();
        $this->token = $user->createToken('terminal-test')->plainTextToken;
    }

    /** @test */
    public function bad_checksum_submissions_are_quarantined_and_return_422()
    {
        $submissionId = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        $transaction = [
            'transaction_id' => $transactionId,
            'transaction_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.00,
            'net_sales' => 100.00,
            'promo_status' => 'NONE',
            'customer_code' => $this->company->customer_code,
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
                ['adjustment_type' => 'senior_discount', 'amount' => 0],
                ['adjustment_type' => 'pwd_discount', 'amount' => 0],
                ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
                ['adjustment_type' => 'employee_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 0],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 100.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0],
            ],
        ];

        // compute valid transaction checksum then tamper
        $transaction['payload_checksum'] = $this->checksumService->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => $submissionId,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];

        // compute valid submission checksum then tamper it to force mismatch
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
        $payload['payload_checksum'] = str_repeat('0', 64); // invalid

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(422);

        $this->assertDatabaseHas('ingestion_quarantine', [
            'submission_uuid' => $submissionId,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'status' => 'NEW'
        ]);
    }
}
