<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\IngestionQueueRouter;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class IngestionBackpressureTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_endpoint_accepts_when_processing_queue_is_below_threshold_and_dispatches_to_checked_shard(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 0);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(200);
        Queue::assertPushed(ProcessTransactionJob::class, fn (ProcessTransactionJob $job) => $job->queue === $queue);
    }

    public function test_official_endpoint_returns_429_with_retry_metadata_when_backpressure_is_enforced(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);
        config()->set('tsms.intake.backpressure.retry_after_seconds', 45);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 10);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertHeader('Retry-After', '45')
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonPath('retry_after_seconds', 45)
            ->assertJsonPath('backpressure.queue', $queue)
            ->assertJsonPath('backpressure.queue_depth', 10)
            ->assertJsonPath('backpressure.threshold', 10);

        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_backpressure_runs_before_form_request_database_validation(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 1);

        $payload = $this->officialPayload($tenant->id, 999999, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonMissingValidationErrors(['terminal_id']);

        Queue::assertNothingPushed();
    }

    public function test_batch_endpoint_returns_429_when_backpressure_is_enforced(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 1);

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonPath('backpressure.queue', $queue);

        Queue::assertNothingPushed();
    }

    public function test_transaction_intake_service_dispatches_to_computed_intake_shard(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 0);

        $payload = $this->intakePayload();
        $request = Request::create('/api/v1/transactions/official', 'POST', $payload);
        $request->setUserResolver(fn () => $terminal);

        $checksum = Mockery::mock(PayloadChecksumService::class);
        $checksum->shouldReceive('validateSubmissionChecksums')->once()->andReturn(['valid' => true]);

        $service = new TransactionIntakeService(
            $checksum,
            app(IngestionQueueRouter::class),
            app(\App\Services\IngestionBackpressureService::class)
        );

        $result = $service->handleIntake($request);

        $this->assertTrue($result['success']);
        Queue::assertPushed(ProcessTransactionIntakeJob::class, fn (ProcessTransactionIntakeJob $job) => $job->queue === $queue);
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

    private function mockRedisDepth(string $queue, int $depth): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->once()->with('queues:' . $queue)->andReturn($depth);

        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'BP-' . Str::upper(Str::random(8)),
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

    private function batchPayload(PosTerminal $terminal): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-TEST',
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => (string) Str::uuid(),
                    'hardware_id' => $terminal->serial_number,
                    'gross_sales' => 100.0,
                    'net_sales' => 100.0,
                    'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                    'payload_checksum' => hash('sha256', (string) Str::uuid()),
                    'items' => [
                        ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                    ],
                ],
            ],
        ];
    }

    private function intakePayload(): array
    {
        return [
            'submission_uuid' => (string) Str::uuid(),
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'payload_checksum' => hash('sha256', 'submission'),
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'receipt_no' => 'BP-' . Str::upper(Str::random(8)),
            ],
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
