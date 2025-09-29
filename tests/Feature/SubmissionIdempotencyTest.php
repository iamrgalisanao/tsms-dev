<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Models\Tenant;
use App\Models\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PayloadChecksumService;

class SubmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        return [$tenant, $terminal];
    }

    private function makePayload($tenantId, $terminalId, $submissionUuid): array
    {
        // minimal valid one-transaction payload matching controller expectations
        $txId = (string) Str::uuid();
        $now = Carbon::now('UTC');

        // Build transaction scalars first
        $txnScalars = [
            'transaction_id' => (string) $txId,
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

        // Compute transaction checksum (excluding its payload_checksum)
        $service = new PayloadChecksumService();
        $txnCopyForChecksum = array_merge($txnScalars, [
            // arrays follow later; but checksum canonicalizer is order-insensitive
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);
        $txnChecksum = $service->computeChecksum($txnCopyForChecksum);

        // Construct transaction in required order: scalars → payload_checksum → arrays
        $transaction = array_merge($txnScalars, [
            'payload_checksum' => $txnChecksum,
        ], [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);

        // Build submission for checksum (without submission payload_checksum)
        $submissionForChecksum = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];

        // Compute submission checksum
        $submissionChecksum = $service->computeChecksum($submissionForChecksum);

        // Final payload in required order: scalars → payload_checksum → transaction
        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'payload_checksum' => $submissionChecksum,
            'transaction' => $transaction,
        ];

        return $payload;
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $uuid = (string) Str::uuid();
        $payload = $this->makePayload($tenant->id, $terminal->id, $uuid);

        // Issue a Sanctum token for the terminal with proper abilities
        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // First request should create the submission
        $res1 = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $res1->assertStatus(200);
        $this->assertDatabaseHas('transaction_submissions', [
            'submission_uuid' => $uuid,
            'terminal_id' => $terminal->id,
        ]);

        // Second request (same UUID + terminal) should return idempotent success instead of 500
        $res2 = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $res2->assertStatus(200);
        $res2->assertJson([
            'success' => true,
            'message' => 'Submission already processed (idempotent)'
        ]);
    }
}
