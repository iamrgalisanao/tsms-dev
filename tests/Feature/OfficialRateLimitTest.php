<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Tenant;
use App\Models\PosTerminal;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Services\PayloadChecksumService;

class OfficialRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Enable rate limiting during tests for this suite
        config()->set('rate-limiting.enable_in_tests', true);
        config()->set('rate-limiting.default_limits.api.attempts', 3);
        config()->set('rate-limiting.default_limits.api.decay_minutes', 10);
    }

    private function buildOfficialPayload(int $tenantId, int $terminalId, string $submissionUuid, ?string $txId = null): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $txId = $txId ?: (string) Str::uuid();

        // Scalars first
        $txnScalars = [
            'transaction_id' => $txId,
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\\TH:i:s\\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
        ];
        // Required 7 adjustments
        $txnAdjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
        // At least 4 taxes including SC_VAT_EXEMPT_SALES
        $txnTaxes = [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];

        // Compute checksums on canonical content (order-insensitive)
        $txnForChecksum = array_merge($txnScalars, [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);
        $txnChecksum = $service->computeChecksum($txnForChecksum);

        // Build transaction in required order: scalars → payload_checksum → arrays
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
        $submissionChecksum = $service->computeChecksum($submissionForChecksum);
        // Final submission with checksum before transaction per TSMS rules
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

    public function test_official_endpoint_returns_429_when_rate_limited(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // Make more than configured attempts within window
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->buildOfficialPayload($tenant->id, $terminal->id, (string) Str::uuid());
            $this->postJson('/api/v1/transactions/official', $payload, $headers)
                 ->assertStatus(200);
        }

        // Next request should hit the limiter and return 429
        $payload = $this->buildOfficialPayload($tenant->id, $terminal->id, (string) Str::uuid());
        $res = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $res->assertStatus(429);
        $res->assertJsonStructure(['message', 'retry_after']);
    }
}
