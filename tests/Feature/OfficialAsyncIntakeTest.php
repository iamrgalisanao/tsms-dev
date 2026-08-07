<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\IngestionQueueRouter;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OfficialAsyncIntakeTest extends TestCase
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
        // orthogonal to this file's exact Redis-call-count assertions for
        // backpressure. Disabled here to keep those counts scoped to
        // IngestionBackpressureService alone.
        config()->set('tsms.circuit_breaker.enabled', false);
    }

    public function test_official_endpoint_accepts_durably_and_dispatches_intake_after_commit(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $this->mockRedisDepths([
            $processingQueue => 0,
            $intakeQueue => 0,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('retryable', false);

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => $payload['payload_checksum'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
        ]);

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertNotNull($intake->queued_at);

        Queue::assertPushed(
            ProcessTransactionIntakeJob::class,
            fn (ProcessTransactionIntakeJob $job) => $job->intakeId === $intake->id
                && $job->queue === $intakeQueue
                && $job->afterCommit === true
        );

        Sanctum::actingAs($terminal, ['transaction:read', 'provider:testing']);

        $this->getJson('/api/v1/submissions/' . $payload['submission_uuid'])
            ->assertStatus(200)
            ->assertJsonPath('data.submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('data.provider_status', 'queued')
            ->assertJsonPath('data.intake_status', TransactionIntake::INTAKE_STATUS_QUEUED);
    }

    public function test_official_endpoint_rejects_malformed_adjustment_and_tax_rows_before_queueing(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepths([$processingQueue => 0]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $payload['transaction']['adjustments'] = array_fill(0, 7, ['bad_key' => 'missing']);
        $payload['transaction']['taxes'] = array_fill(0, 4, ['bad_key' => 'missing']);
        $payload = $this->refreshChecksums($payload);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE')
            ->assertJsonValidationErrors([
                'transaction.adjustments.0.adjustment_type',
                'transaction.adjustments.0.amount',
                'transaction.taxes.0.tax_type',
                'transaction.taxes.0.amount',
            ]);

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            'last_error_code' => 'STRUCTURAL_VALIDATION_FAILURE',
        ]);
        $this->assertDatabaseMissing('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_rejects_missing_required_adjustment_and_tax_types_before_queueing(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepths([$processingQueue => 0]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $payload['transaction']['adjustments'] = array_fill(0, 7, ['adjustment_type' => 'promo_discount', 'amount' => 0]);
        $payload['transaction']['taxes'] = array_fill(0, 4, ['tax_type' => 'OTHER_TAX', 'amount' => 0]);
        $payload = $this->refreshChecksums($payload);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE')
            ->assertJsonValidationErrors(['transaction.adjustments', 'transaction.taxes']);

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            'last_error_code' => 'STRUCTURAL_VALIDATION_FAILURE',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_rejects_hardware_id_mismatch_before_queueing(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepths([$processingQueue => 0]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), 'FOREIGN-HARDWARE');
        $payload = $this->refreshChecksums($payload);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'HARDWARE_ID_MISMATCH')
            ->assertJsonValidationErrors(['hardware_id']);

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            'last_error_code' => 'HARDWARE_ID_MISMATCH',
        ]);
        Queue::assertNothingPushed();
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

    private function mockRedisDepths(array $depths): void
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
            'receipt_no' => 'ASYNC-' . Str::upper(Str::random(8)),
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

    private function refreshChecksums(array $payload): array
    {
        $service = new PayloadChecksumService();

        if (isset($payload['transaction']) && is_array($payload['transaction'])) {
            $transaction = $payload['transaction'];
            unset($transaction['payload_checksum']);
            $payload['transaction']['payload_checksum'] = $service->computeChecksum($transaction);
        }

        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            foreach ($payload['transactions'] as $index => $transaction) {
                unset($transaction['payload_checksum']);
                $payload['transactions'][$index]['payload_checksum'] = $service->computeChecksum($transaction);
            }
        }

        $submission = $payload;
        unset($submission['payload_checksum']);
        $payload['payload_checksum'] = $service->computeChecksum($submission);

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
