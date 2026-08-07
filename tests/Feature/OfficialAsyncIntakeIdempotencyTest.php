<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Models\TransactionSubmission;
use App\Services\IngestionQueueRouter;
use App\Services\IngestionBackpressureService;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIntakeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfficialAsyncIntakeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This route also carries 'circuit.breaker:transaction-intake'
        // (pre-existing, unchanged middleware). Since the circuit breaker
        // is now Redis-backed, any request completing with a non-5xx
        // status transitively touches Redis via
        // CircuitBreakerMiddleware's isAvailable()/recordSuccess() —
        // orthogonal to this file's exact Redis-call-count assertions.
        // Disabled here to keep those counts scoped to
        // IngestionBackpressureService alone.
        config()->set('tsms.circuit_breaker.enabled', false);
    }

    public function test_different_terminal_submission_uuid_conflict_uses_unified_idempotency_shape(): void
    {
        Queue::fake();

        [$firstTenant, $firstTerminal] = $this->seedTenantAndTerminal();
        [$secondTenant, $secondTerminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();

        TransactionSubmission::create([
            'tenant_id' => $firstTenant->id,
            'terminal_id' => $firstTerminal->id,
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now(),
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', 'first-payload'),
            'status' => TransactionSubmission::STATUS_COMPLETED,
        ]);

        $this->mockProcessingDepth($secondTenant->id, 0);
        $payload = $this->officialPayload($secondTenant->id, $secondTerminal->id, $submissionUuid, $secondTerminal->serial_number);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($secondTerminal))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT')
            ->assertJsonPath('submission_uuid', $submissionUuid)
            ->assertJsonStructure(['correlation_id', 'conflict' => ['terminal_id', 'payload_checksum']]);
    }

    public function test_same_terminal_payload_drift_conflict_uses_unified_idempotency_shape(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();

        TransactionSubmission::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now(),
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', 'original-payload'),
            'status' => TransactionSubmission::STATUS_COMPLETED,
        ]);

        $this->mockProcessingDepth($tenant->id, 0);
        $payload = $this->officialPayload($tenant->id, $terminal->id, $submissionUuid, $terminal->serial_number);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT')
            ->assertJsonPath('submission_uuid', $submissionUuid)
            ->assertJsonStructure(['correlation_id', 'conflict' => ['transaction_count', 'payload_checksum']]);
    }

    public function test_intake_layer_duplicate_payload_drift_uses_unified_idempotency_shape(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $submissionUuid, $terminal->serial_number);

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => hash('sha256', 'original-intake-payload'),
            'payload' => $payload,
            'payload_size_bytes' => strlen(json_encode($payload)),
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now(),
            'queued_at' => now(),
        ]);

        $this->mockProcessingDepth($tenant->id, 0);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT')
            ->assertJsonPath('submission_uuid', $submissionUuid)
            ->assertJsonStructure(['correlation_id', 'conflict' => ['intake_id', 'payload_checksum']]);
    }

    public function test_concurrent_duplicate_intake_insert_race_returns_idempotent_existing_response(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        // handleOfficialIntake() is invoked directly below (bypassing
        // IngestionBackpressureMiddleware), so checkAggregate() evaluates
        // both queues fresh — both need mocking, not just intake.
        $this->mockQueueDepths([$queue => 0, $processingQueue => 0]);

        $request = Request::create(
            '/api/v1/transactions/official',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CORRELATION_ID' => (string) Str::uuid()],
            json_encode($payload)
        );
        $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
        $request->setUserResolver(fn () => $terminal);

        $service = new class(
            app(PayloadChecksumService::class),
            app(IngestionQueueRouter::class),
            app(IngestionBackpressureService::class)
        ) extends TransactionIntakeService {
            protected function persistQueuedIntake(
                array $payload,
                \App\Models\PosTerminal $terminal,
                Request $request,
                string $sourceIp,
                string $traceId,
                \Carbon\Carbon $receivedAt,
                string $shardQueue
            ): TransactionIntake {
                TransactionIntake::create([
                    'submission_uuid' => $payload['submission_uuid'],
                    'tenant_id' => $terminal->tenant_id,
                    'terminal_id' => $terminal->id,
                    'payload_checksum' => $payload['payload_checksum'],
                    'payload' => $payload,
                    'payload_size_bytes' => strlen($request->getContent()),
                    'source_ip' => $sourceIp,
                    'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                    'trace_id' => $traceId,
                    'received_at' => $receivedAt,
                    'queued_at' => now(),
                ]);

                throw new UniqueConstraintViolationException(
                    'mysql',
                    'insert into transaction_intake',
                    [],
                    new \Exception('Duplicate entry')
                );
            }
        };

        $result = $service->handleOfficialIntake($request);

        $this->assertTrue($result['success']);
        $this->assertSame(202, $result['http_status']);
        $this->assertSame($payload['submission_uuid'], $result['submission_uuid']);
        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
        ]);
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

    private function mockProcessingDepth(int $tenantId, int $depth): void
    {
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenantId);
        $this->mockQueueDepth($queue, $depth);
    }

    private function mockQueueDepth(string $queue, int $depth): void
    {
        $this->mockQueueDepths([$queue => $depth]);
    }

    private function mockQueueDepths(array $depths): void
    {
        $redis = Mockery::mock();
        foreach ($depths as $queue => $depth) {
            $redis->shouldReceive('llen')->once()->with('queues:' . $queue)->andReturn($depth);
        }

        Redis::shouldReceive('connection')->times(count($depths))->with('default')->andReturn($redis);
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'IDEMP-' . Str::upper(Str::random(8)),
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
