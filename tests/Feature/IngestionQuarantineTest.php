<?php

namespace Tests\Feature;

use App\Models\IngestionQuarantine;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestionQuarantineTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_official_checksum_is_quarantined(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $payload = $this->makePayload($tenant->id, $terminal->id, $terminal->serial_number);
        $payload['payload_checksum'] = str_repeat('f', 64);

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
                'Content-Type' => 'application/json',
            ])
            ->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Invalid payload checksum',
        ]);

        $this->assertDatabaseHas('ingestion_quarantine', [
            'submission_uuid' => $payload['submission_uuid'],
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum_received' => str_repeat('f', 64),
            'status' => IngestionQuarantine::STATUS_NEW,
        ]);

        $quarantine = IngestionQuarantine::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame('CHECKSUM_VALIDATION_FAILED', $quarantine->metadata['reason']);
        $this->assertArrayHasKey('diagnostics', $quarantine->metadata);
        $this->assertArrayHasKey('v2.1', $quarantine->metadata['diagnostics']);
        $this->assertNotEmpty($quarantine->payload_checksum_computed);
        $this->assertSame($payload['submission_uuid'], json_decode($quarantine->payload, true)['submission_uuid']);
    }

    private function makePayload(int $tenantId, int $terminalId, string $hardwareId): array
    {
        $checksum = new PayloadChecksumService();
        $now = Carbon::now('UTC');

        $adjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
            ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
        ];
        $taxes = [
            ['tax_type' => 'VAT', 'amount' => '0.00'],
            ['tax_type' => 'VATABLE_SALES', 'amount' => '100.00'],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
            ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
        ];

        $transactionScalars = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'Q-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => '100.00',
            'net_sales' => '100.00',
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
        ];
        $transactionForChecksum = array_merge($transactionScalars, [
            'adjustments' => $adjustments,
            'taxes' => $taxes,
        ]);

        $transaction = array_merge($transactionScalars, [
            'payload_checksum' => $checksum->computeChecksum($transactionForChecksum),
            'adjustments' => $adjustments,
            'taxes' => $taxes,
        ]);

        $submission = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];

        return [
            'submission_uuid' => $submission['submission_uuid'],
            'tenant_id' => $submission['tenant_id'],
            'terminal_id' => $submission['terminal_id'],
            'submission_timestamp' => $submission['submission_timestamp'],
            'transaction_count' => $submission['transaction_count'],
            'payload_checksum' => $checksum->computeChecksum($submission),
            'transaction' => $transaction,
        ];
    }
}
