<?php

namespace Tests\Unit\Services;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\CircuitBreaker;
use App\Services\DeadlockRetryService;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIngestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

/**
 * Exercises the REAL (non-stubbed) TransactionIngestService::ingest() path
 * against a fake-Redis-backed CircuitBreaker, closing the coverage gap
 * where every other test that runs ProcessTransactionIntakeJob/ingest()
 * stubs ingest() out with an anonymous class or mock — meaning the
 * recordSuccess()/recordFailure() calls added inside ingest() had never
 * actually executed under test.
 */
class TransactionIngestServiceCircuitBreakerTest extends TestCase
{
    use RefreshDatabase, FakesCircuitBreakerRedis;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_real_ingest_success_genuinely_invokes_record_success(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // A success recorded while the breaker is already closed is a
        // deliberate no-op (see CircuitBreakerTest::test_success_in_closed_state_is_a_noop),
        // so it wouldn't prove recordSuccess() actually ran. Force the
        // shared 'transaction-intake' breaker into half-open first, via a
        // separate instance sharing the same fake Redis store/key, so a
        // real recordSuccess() call is observable as a state transition.
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $breaker = new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY);
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));
        $this->assertTrue($breaker->isAvailable()); // transitions to half-open

        $service = app(TransactionIngestService::class);

        $result = $service->ingest($this->transactionPayload(
            'REAL-SUCCESS-' . Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('accepted', $result['status']);

        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:' . CircuitBreaker::INGESTION_SERVICE_KEY);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state']);
        $this->assertSame('0', (string) $state['failure_count']);
    }

    public function test_real_ingest_infra_exception_genuinely_invokes_record_failure(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // Force a genuine infrastructure-classified QueryException at the
        // real DB-write choke point (insertTransactionParent), leaving the
        // rest of ingest() — normalization, receipt-conflict lookup, the
        // deadlock-retry wrapper, the outer catch block — running for real.
        $service = new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class)) extends TransactionIngestService {
            protected function insertTransactionParent(array $parent): int
            {
                $previous = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 0);

                throw new QueryException('mysql', 'insert into transactions ...', [], $previous);
            }
        };

        $result = $service->ingest($this->transactionPayload(
            'REAL-INFRA-FAIL-' . Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Reference:', $result['details']);
        $this->assertStringNotContainsString('SQLSTATE', $result['details']);

        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:' . CircuitBreaker::INGESTION_SERVICE_KEY);
        $this->assertSame('1', (string) $state['failure_count']);
    }

    public function test_real_ingest_data_constraint_exception_does_not_invoke_record_failure(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // Same choke point, but the underlying PDOException is
        // constraint-level (SQLSTATE 23000) — a value that slipped past
        // validation and hit the DB directly, not an infra outage.
        $service = new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class)) extends TransactionIngestService {
            protected function insertTransactionParent(array $parent): int
            {
                $previous = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 0);

                throw new QueryException('mysql', 'insert into transactions ...', [], $previous);
            }
        };

        $result = $service->ingest($this->transactionPayload(
            'REAL-DATA-FAIL-' . Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('failed', $result['status']);

        // No Redis write should have occurred at all — recordFailure() was
        // never invoked for this classification.
        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:' . CircuitBreaker::INGESTION_SERVICE_KEY);
        $this->assertSame([], $state);
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function transactionPayload(string $transactionId, string $hardwareId, int $tenantId, int $terminalId): array
    {
        return [
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'transaction_id' => $transactionId,
            'hardware_id' => $hardwareId,
            'receipt_no' => 'IB-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => now()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'payload_checksum' => str_repeat('b', 64),
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
            ],
        ];
    }
}
