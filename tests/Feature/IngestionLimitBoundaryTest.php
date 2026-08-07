<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestionLimitBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_payload_exactly_at_byte_limit_is_not_rejected_for_size(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', false);
        config()->set('tsms.intake.max_batch_count', 500);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $exactBytes = strlen(json_encode($payload));
        config()->set('tsms.intake.max_payload_bytes', $exactBytes);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame(413, $response->getStatusCode());
        $this->assertNotSame('PAYLOAD_TOO_LARGE', $response->json('error_code'));
    }

    public function test_official_payload_one_byte_over_limit_is_rejected_for_size(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', false);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $exactBytes = strlen(json_encode($payload));
        config()->set('tsms.intake.max_payload_bytes', $exactBytes - 1);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(413)
            ->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE')
            ->assertJsonPath('max_payload_bytes', $exactBytes - 1);
    }

    public function test_official_batch_exactly_at_max_count_is_not_rejected_for_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.max_batch_count', 5);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, (string) Str::uuid(), 5);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame('BATCH_LIMIT_EXCEEDED', $response->json('error_code'));
        $this->assertNotSame('PAYLOAD_TOO_LARGE', $response->json('error_code'));
    }

    public function test_official_batch_one_over_max_count_is_rejected_for_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.max_batch_count', 5);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, (string) Str::uuid(), 6);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('max_batch_count', 5);
    }

    public function test_batch_endpoint_exactly_at_max_count_is_not_rejected_for_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.max_batch_count', 3);
        config()->set('tsms.intake.backpressure.enabled', false);

        [, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->batchStorePayload($terminal, 3);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $this->assertNotSame('BATCH_LIMIT_EXCEEDED', $response->json('error_code'));
        $this->assertNotSame('PAYLOAD_TOO_LARGE', $response->json('error_code'));
    }

    public function test_batch_endpoint_one_over_max_count_is_rejected_for_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.max_batch_count', 3);

        [, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->batchStorePayload($terminal, 4);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('max_batch_count', 3);
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function headersFor(PosTerminal $terminal): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'BND-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'adjustments' => $this->adjustments(),
            'taxes' => $this->taxes(),
        ];
        $transaction['payload_checksum'] = $service->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
    }

    /**
     * A minimal (deliberately not fully structurally valid) official batch
     * payload, used only to exercise the batch-count guard which runs
     * before structural validation.
     */
    private function officialBatchPayload(int $tenantId, int $terminalId, string $submissionUuid, int $count): array
    {
        return [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => $count,
            'transactions' => array_map(
                fn () => ['transaction_id' => (string) Str::uuid()],
                range(1, $count)
            ),
            'payload_checksum' => str_repeat('a', 64),
        ];
    }

    private function batchStorePayload(PosTerminal $terminal, int $count): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-TEST',
            'terminal_id' => $terminal->id,
            'transactions' => array_map(fn () => [
                'transaction_id' => (string) Str::uuid(),
                'hardware_id' => $terminal->serial_number,
                'gross_sales' => 100.0,
                'net_sales' => 100.0,
                'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                'payload_checksum' => hash('sha256', (string) Str::uuid()),
                'items' => [
                    ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                ],
            ], range(1, $count)),
        ];
    }

    private function adjustments(): array
    {
        return [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
    }

    private function taxes(): array
    {
        return [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];
    }
}
