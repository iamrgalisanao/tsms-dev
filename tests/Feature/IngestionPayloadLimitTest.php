<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use App\Support\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestionPayloadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_endpoint_rejects_payload_over_byte_limit_with_413(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 50);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        // The payload-size middleware runs ahead of everything else, so no
        // Redis lookup for backpressure should ever happen for this request.
        Redis::shouldReceive('connection')->never();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE')
            ->assertJsonPath('max_payload_bytes', 50);

        $this->assertNotEmpty($response->json('correlation_id'));
        $this->assertDatabaseMissing('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
        ]);
        Queue::assertNothingPushed();
    }

    /** WU4 (T053 remainder): rejection-reason counter for the payload-size middleware. */
    public function test_official_endpoint_payload_rejection_increments_rejection_metric(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 50);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->assertSame(0, Metrics::get('ingestion.rejected.payload_size', 0));

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(413);

        $this->assertSame(1, Metrics::get('ingestion.rejected.payload_size', 0));
    }

    public function test_official_endpoint_accepts_payload_within_byte_limit(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.backpressure.enabled', false);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(200);
        $this->assertNotSame('PAYLOAD_TOO_LARGE', $response->json('error_code'));
    }

    // Retargeted from the retired /transactions/batch route: a multi-transaction
    // submission now goes to /official as a `transactions` array.
    public function test_official_multi_transaction_submission_rejects_payload_over_byte_limit_with_413(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 50);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, $terminal->serial_number, 2);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE')
            ->assertJsonPath('max_payload_bytes', 50);

        $this->assertNotEmpty($response->json('correlation_id'));
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
        ]);
    }

    // Retargeted from the retired /transactions/batch route (see above).
    public function test_official_multi_transaction_submission_accepts_payload_within_byte_limit(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 2097152);
        config()->set('tsms.intake.backpressure.enabled', false);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, $terminal->serial_number, 2);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame(413, $response->getStatusCode());
        $this->assertNotSame('PAYLOAD_TOO_LARGE', $response->json('error_code'));
    }

    /**
     * Ordering lock: the payload-size middleware must be registered ahead of
     * ingestion.backpressure on the official route. Configure backpressure to
     * enforce on every request (max_queue_depth 0) and assert that an
     * oversized request still gets rejected as PAYLOAD_TOO_LARGE -- and that
     * the backpressure service's Redis dependency is never even consulted --
     * proving the payload-size check short-circuits first. If a future
     * change silently reorders these two middleware, this test starts
     * failing (either the status flips to 429/503, or the Redis mock's
     * unexpected-call assertion fails).
     */
    public function test_payload_size_rejection_runs_before_backpressure_check(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 50);
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 0);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        Redis::shouldReceive('connection')->never();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(413)
            ->assertJsonPath('error_code', 'PAYLOAD_TOO_LARGE');
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
            'receipt_no' => 'PLT-' . Str::upper(Str::random(8)),
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

    private function officialBatchPayload(int $tenantId, int $terminalId, string $hardwareId, int $count): array
    {
        $service = new PayloadChecksumService;
        $transactions = array_map(
            fn () => $this->officialPayload($tenantId, $terminalId, (string) Str::uuid(), $hardwareId)['transaction'],
            range(1, $count)
        );

        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => $count,
            'transactions' => $transactions,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
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
