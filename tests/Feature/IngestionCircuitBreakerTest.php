<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

class IngestionCircuitBreakerTest extends TestCase
{
    use RefreshDatabase, FakesCircuitBreakerRedis;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate these tests from the ingestion queue-depth backpressure gate
        // entirely — this file exercises the transaction-intake circuit
        // breaker, a distinct concern.
        config()->set('tsms.intake.backpressure.enabled', false);

        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.redis_connection', 'default');
        config()->set('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:');
        config()->set('tsms.circuit_breaker.failure_threshold', 3);
        config()->set('tsms.circuit_breaker.reset_timeout_seconds', 60);
        config()->set('tsms.circuit_breaker.state_ttl_seconds', 3600);

        $this->fakeCircuitBreakerRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_validation_failures_do_not_open_the_breaker(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // Exceed the failure_threshold (3) with checksum-invalid requests.
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $payload['payload_checksum'] = str_repeat('0', 64); // deliberately wrong

            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'CRYPTOGRAPHIC_INTEGRITY_FAILURE');
        }

        // A subsequent, fully valid request must still succeed — validation
        // failures (4xx) must never trip the breaker.
        $validPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $validPayload, $this->headersFor($terminal))
            ->assertStatus(202)
            ->assertJsonPath('success', true);
    }

    public function test_backpressure_rejections_do_not_open_the_breaker(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // Share the same connection('default') double the circuit-breaker
        // fake already registered, rather than racing a second competing
        // Redis::shouldReceive('connection') expectation.
        $this->fakeCircuitBreakerRedisDouble()->shouldReceive('llen')->andReturn(5);

        // Exceed the failure_threshold (3) worth of 429 backpressure rejections.
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(429)
                ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE');
        }

        // The route-level backpressure middleware short-circuits before the
        // circuit breaker middleware ever runs, so 429s must never count
        // against the breaker. Prove it: once the queue check is disabled
        // again, a normal request succeeds rather than hitting an open
        // circuit (the earlier Redis mock is simply never consulted again).
        config()->set('tsms.intake.backpressure.enabled', false);

        $validPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $validPayload, $this->headersFor($terminal))
            ->assertStatus(202)
            ->assertJsonPath('success', true);
    }

    public function test_aggregate_backpressure_degraded_response_does_not_open_the_breaker(): void
    {
        // Re-verifies the Critical fix: a Redis blip on the *queue-depth*
        // health check (unrelated to the actual ingest/DB pipeline) must
        // never trip the shared ingestion circuit breaker, even when it
        // repeatedly returns 503 INGESTION_DEGRADED in enforce mode.
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $router = app(\App\Services\IngestionQueueRouter::class);
        $processingQueue = $router->processingQueueForTenant($tenant->id);
        $intakeQueue = $router->intakeQueueForTenant($tenant->id);

        // Share the same connection('default') double the circuit-breaker
        // fake already registered, rather than racing a second competing
        // Redis::shouldReceive('connection') expectation.
        $double = $this->fakeCircuitBreakerRedisDouble();
        $double->shouldReceive('llen')->with('queues:' . $processingQueue)->andReturn(0); // healthy

        $intakeThrows = true;
        $double->shouldReceive('llen')
            ->with('queues:' . $intakeQueue)
            ->andReturnUsing(function () use (&$intakeThrows) {
                if ($intakeThrows) {
                    throw new \RuntimeException('Redis down');
                }
                return 0;
            });

        // Drive exactly failure_threshold (3) worth of degraded 503s.
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'INGESTION_DEGRADED');
        }

        // Prove the breaker is still closed: make the intake health check
        // healthy again and confirm a normal request succeeds, rather than
        // hitting an open circuit.
        $intakeThrows = false;

        $validPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $validPayload, $this->headersFor($terminal))
            ->assertStatus(202)
            ->assertJsonPath('success', true);
    }

    public function test_repeated_infrastructure_failures_open_the_breaker(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $shouldFail = true;
        TransactionIntake::creating(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Simulated DB outage');
            }
        });

        // Drive exactly failure_threshold (3) infrastructure failures.
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'INTAKE_PERSISTENCE_FAILED');
        }

        // The breaker should now be open. Stop forcing DB failures — if the
        // next response is still the *original* 503 shape (not the intake
        // service's), the request never reached the controller/DB at all.
        $shouldFail = false;
        $countBefore = TransactionIntake::count();

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(503)
            ->assertJson([
                'error' => 'Service unavailable',
                'service' => 'transaction-intake',
                'message' => 'Circuit is open due to multiple failures',
            ]);

        $this->assertSame($countBefore, TransactionIntake::count());
    }

    public function test_successful_requests_keep_the_breaker_closed_and_a_prior_partial_failure_count_resets(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $shouldFail = true;
        TransactionIntake::creating(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Simulated DB outage');
            }
        });

        // Drive the breaker fully open (failure_threshold = 3).
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'INTAKE_PERSISTENCE_FAILED');
        }

        // Confirm it is genuinely open before touching the clock.
        $openPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $openPayload, $this->headersFor($terminal))
            ->assertStatus(503)
            ->assertJson(['message' => 'Circuit is open due to multiple failures']);

        // Advance past reset_timeout_seconds (60) so the breaker becomes
        // half-open, then let a request succeed — a success while half-open
        // resets the failure count back to zero (unlike a success while
        // still closed, which is deliberately a no-op — see
        // tests/Unit/Services/CircuitBreakerTest::test_success_in_closed_state_is_a_noop).
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $shouldFail = false;
        $successPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $successPayload, $this->headersFor($terminal))
            ->assertStatus(202);

        // Prove the failure count actually reset to zero: two more
        // failures (below the threshold of 3) must NOT reopen the breaker.
        // If the reset hadn't happened, the stale count (>= threshold from
        // before) would reopen it after just one more failure.
        $shouldFail = true;
        for ($i = 0; $i < 2; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'INTAKE_PERSISTENCE_FAILED'); // still the service's own 503, not "circuit open"
        }
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
            'receipt_no' => 'CB-' . Str::upper(Str::random(8)),
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
