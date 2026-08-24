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
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED')
            ->assertJsonPath('submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('data.submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.checksum_validation', 'passed')
            ->assertJsonPath('data.transactions.0.transaction_id', $payload['transaction']['transaction_id'])
            ->assertJsonPath('data.transactions.0.status', 'PENDING')
            ->assertJsonPath('data.transactions.0.validation_status', 'VALID')
            ->assertJsonPath('data.transactions.0.job_status', 'PENDING');

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

        $this->getJson('/api/v1/submissions/'.$payload['submission_uuid'])
            ->assertStatus(200)
            ->assertJsonPath('data.submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('data.provider_status', 'queued')
            ->assertJsonPath('data.intake_status', TransactionIntake::INTAKE_STATUS_QUEUED);
    }

    public function test_official_endpoint_replay_reports_completed_intake_truthfully(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedExistingIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->mockRedisForAdmissionMiddleware();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('code', 'COMPLETED')
            ->assertJsonPath('data.intake_status', 'PENDING')
            ->assertJsonPath('data.processing_status', 'COMPLETED')
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.checksum_validation', 'matched_stored_checksum')
            ->assertJsonPath('data.transactions.0.status', 'COMPLETED')
            ->assertJsonPath('data.transactions.0.validation_status', 'VALID')
            ->assertJsonPath('data.transactions.0.job_status', 'COMPLETED');

        $this->assertStringNotContainsString('QUEUED', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_official_endpoint_replay_reports_permanent_failure_truthfully(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedExistingIntake(
            $payload,
            $terminal,
            TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
            'CRYPTOGRAPHIC_INTEGRITY_FAILURE'
        );
        $this->mockRedisForAdmissionMiddleware();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(200)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'FAILED')
            ->assertJsonPath('data.intake_status', 'PENDING')
            ->assertJsonPath('data.processing_status', 'FAILED')
            ->assertJsonPath('data.last_error_code', 'CRYPTOGRAPHIC_INTEGRITY_FAILURE')
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.checksum_validation', 'matched_stored_checksum')
            ->assertJsonPath('data.transactions.0.status', 'FAILED')
            ->assertJsonPath('data.transactions.0.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.0.job_status', 'FAILED');

        $this->assertStringNotContainsString('QUEUED', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_official_endpoint_replay_keeps_retryable_failure_pending(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedExistingIntake(
            $payload,
            $terminal,
            TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
            'TEMPORARY_WORKER_FAILURE'
        );
        $this->mockRedisForAdmissionMiddleware();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED')
            ->assertJsonPath('data.intake_status', 'PENDING')
            ->assertJsonPath('data.processing_status', 'PENDING')
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.checksum_validation', 'matched_stored_checksum')
            ->assertJsonPath('data.transactions.0.status', 'PENDING')
            ->assertJsonPath('data.transactions.0.validation_status', 'VALID')
            ->assertJsonPath('data.transactions.0.job_status', 'PENDING');

        $this->assertStringNotContainsString('QUEUED', json_encode($response->json(), JSON_THROW_ON_ERROR));
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
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
        ];
    }

    private function mockRedisDepths(array $depths): void
    {
        $redis = Mockery::mock();
        foreach ($depths as $queue => $depth) {
            $redis->shouldReceive('llen')->once()->with('queues:'.$queue)->andReturn($depth);
        }

        // T045 wired IngestionFairnessMiddleware onto this route, which
        // also calls Redis::connection('default')->eval(...) (up to 3x per
        // request: global/tenant/terminal checks) on every request that
        // reaches it. That call volume is incidental to this file's
        // backpressure-focused assertions, so it is deliberately left
        // un-asserted here rather than folded into a hand-counted total —
        // a fixed connection()->times(N) total would need re-calibrating
        // every time another Redis-backed concern is layered onto this
        // route (exactly what just broke). The llen expectations above
        // already assert precisely what this file cares about
        // (backpressure's own queue-depth reads); fairness itself still
        // genuinely runs, and a fresh/unseeded window (eval always
        // returning a count of 1, comfortably under any configured limit)
        // naturally evaluates as allowed.
        $redis->shouldReceive('eval')->zeroOrMoreTimes()->andReturn(1);

        Redis::shouldReceive('connection')->with('default')->andReturn($redis);
    }

    private function mockRedisForAdmissionMiddleware(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->zeroOrMoreTimes()->andReturn(1);

        Redis::shouldReceive('connection')->with('default')->andReturn($redis);
    }

    private function seedExistingIntake(
        array $payload,
        PosTerminal $terminal,
        string $processingStatus,
        ?string $lastErrorCode = null
    ): TransactionIntake {
        return TransactionIntake::create([
            'submission_uuid' => $payload['submission_uuid'],
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => $payload['payload_checksum'],
            'payload' => $payload,
            'payload_size_bytes' => strlen(json_encode($payload, JSON_THROW_ON_ERROR)),
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => $processingStatus,
            'attempt_count' => 1,
            'last_error_code' => $lastErrorCode,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now(),
            'queued_at' => now(),
            'processed_at' => now(),
        ]);
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'ASYNC-'.Str::upper(Str::random(8)),
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
        $service = new PayloadChecksumService;

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
