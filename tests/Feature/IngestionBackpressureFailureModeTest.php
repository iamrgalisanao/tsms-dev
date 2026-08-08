<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\IngestionQueueRouter;
use App\Services\IngestionBackpressureService;
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

class IngestionBackpressureFailureModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Not exercised in this file — isolate from the circuit breaker.
        config()->set('tsms.circuit_breaker.enabled', false);
    }

    public function test_route_level_processing_check_returns_503_ingestion_degraded_when_redis_throws_in_enforce_mode(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisThrows($processingQueue);

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $this->headersFor($terminal));

        $response
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INGESTION_DEGRADED')
            ->assertJsonPath('reason', 'processing_health_check_failed')
            ->assertHeader('Retry-After');

        Queue::assertNothingPushed();
    }

    public function test_route_level_processing_check_fails_open_when_redis_throws_in_observe_mode(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'observe');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisThrows($processingQueue);

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $this->headersFor($terminal));

        // Observe mode never enforces, even when the health check itself is
        // unevaluable — the request must reach the controller normally.
        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_official_endpoint_returns_503_ingestion_degraded_when_intake_redis_check_throws_in_enforce_mode(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);

        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->with('queues:' . $processingQueue)->andReturn(0); // healthy
        $redis->shouldReceive('llen')->with('queues:' . $intakeQueue)->andThrow(new \RuntimeException('Redis down'));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        // Note: unlike the route-level middleware's short-circuit response
        // (which explicitly chains ->header('Retry-After', ...)), responses
        // built by TransactionIntakeService and returned through
        // TransactionController::storeOfficial() are plain
        // response()->json($result, $httpStatus) calls with no extra
        // headers — this is a pre-existing gap (unrelated to Wave B, and
        // TransactionController.php is explicitly out of scope for this
        // wave per the design's file list), so only the JSON body's
        // retry_after_seconds is asserted here, not an actual HTTP header.
        $response
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'INGESTION_DEGRADED')
            ->assertJsonPath('reason', 'intake_health_check_failed')
            ->assertJsonPath('retry_after_seconds', 60);

        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_returns_503_ingestion_degraded_when_both_queues_are_unevaluable(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->andThrow(new \RuntimeException('Redis down'));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $request = Request::create('/api/v1/transactions/official', 'POST', $payload);
        $request->setUserResolver(fn () => $terminal);
        // No 'backpressure.processing' attribute set — bypasses the route
        // middleware entirely so checkAggregate() evaluates both queues
        // fresh, itself.

        $checksum = Mockery::mock(PayloadChecksumService::class);
        $checksum->shouldReceive('validateSubmissionChecksums')->once()->andReturn(['valid' => true]);

        $service = new TransactionIntakeService(
            $checksum,
            app(IngestionQueueRouter::class),
            app(IngestionBackpressureService::class)
        );

        $result = $service->handleIntake($request);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['http_status']);
        $this->assertSame('INGESTION_DEGRADED', $result['error_code']);
        $this->assertSame('intake_and_processing_health_check_failed', $result['reason']);

        Queue::assertNothingPushed();
    }

    public function test_degraded_response_body_and_retry_after_header_use_the_same_clamped_value(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);
        // 0 (and negative) must be clamped up to 1 by retryAfterSeconds().
        config()->set('tsms.intake.backpressure.retry_after_seconds', 0);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisThrows($processingQueue);

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $this->headersFor($terminal));

        $response
            ->assertStatus(503)
            ->assertJsonPath('retry_after_seconds', 1)
            ->assertHeader('Retry-After', '1');

        $this->assertSame(
            $response->headers->get('Retry-After'),
            (string) $response->json('retry_after_seconds')
        );
    }

    public function test_degraded_response_includes_correlation_id(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisThrows($processingQueue);

        $headers = $this->headersFor($terminal);
        $headers['X-Request-Id'] = 'test-correlation-id-123';

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $headers);

        $response
            ->assertStatus(503)
            ->assertJsonPath('correlation_id', 'test-correlation-id-123');
    }

    private function mockRedisThrows(string $queue): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->with('queues:' . $queue)->andThrow(new \RuntimeException('Redis down'));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);
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
            'receipt_no' => 'FM-' . Str::upper(Str::random(8)),
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
