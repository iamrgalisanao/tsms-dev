<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfficialIdempotentReplayTest extends TestCase
{
    use RefreshDatabase;

    private function buildOfficialPayloadWithTx(
        string $txId,
        int $tenantId,
        int $terminalId,
        string $submissionUuid,
        string $hardwareId
    ): array {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $txnScalars = [
            'transaction_id' => $txId,
            'hardware_id' => $hardwareId,
            'receipt_no' => 'REPLAY-'.Str::upper(Str::random(8)),
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

    public function test_same_transaction_id_across_distinct_async_submissions_is_accepted_for_processing(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];

        $txId = (string) Str::uuid();
        $this->mockRedisForAdmissionMiddleware();

        // First submission
        $payload1 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload1, $headers)
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED');

        // The async intake layer accepts the second durable submission; duplicate
        // transaction detection happens during downstream processing.
        $payload2 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload2, $headers)
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED')
            ->assertJsonPath('data.transactions.0.status', 'PENDING');
    }

    private function mockRedisForAdmissionMiddleware(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->zeroOrMoreTimes()->andReturn(1);
        $redis->shouldReceive('llen')->zeroOrMoreTimes()->andReturn(0);

        Redis::shouldReceive('connection')->with('default')->zeroOrMoreTimes()->andReturn($redis);
    }
}
