<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Tenant;
use App\Models\PosTerminal;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Services\PayloadChecksumService;

class OfficialIdempotentReplayTest extends TestCase
{
    use RefreshDatabase;

    private function buildOfficialPayloadWithTx(string $txId, int $tenantId, int $terminalId, string $submissionUuid): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $txnScalars = [
            'transaction_id' => $txId,
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\\TH:i:s\\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
        ];
        $txnAdjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
        $txnTaxes = [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];

        $txnForChecksum = array_merge($txnScalars, [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);
        $txnChecksum = $service->computeChecksum($txnForChecksum);
        $transaction = array_merge($txnScalars, [
            'payload_checksum' => $txnChecksum,
        ], [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);

        $submissionForChecksum = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $submissionChecksum = $service->computeChecksum($submissionForChecksum);
        return [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'payload_checksum' => $submissionChecksum,
            'transaction' => $transaction,
        ];
    }

    public function test_replay_same_transaction_id_is_idempotent_across_submissions(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

    $txId = (string) Str::uuid();

        // First submission
        $payload1 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid());
        $this->postJson('/api/v1/transactions/official', $payload1, $headers)
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        // Second submission with a different submission_uuid but same transaction_id should be treated idempotently
        $payload2 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid());
        $res2 = $this->postJson('/api/v1/transactions/official', $payload2, $headers);
        $res2->assertStatus(200);
        $res2->assertJson(['success' => true]);
        // Ensure response transactions array contains a message indicating already processed or queued
        $json = $res2->json();
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('transactions', $json['data']);
        $messages = collect($json['data']['transactions'])->pluck('message')->implode('|');
        $this->assertTrue(str_contains($messages, 'already processed') || str_contains($messages, 'queued'));
    }
}
