<?php

namespace Tests\Unit\Services;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\CircuitBreaker;
use App\Services\DeadlockRetryService;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIngestService;
use App\Support\Metrics;
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
 * recordFailure() call inside ingest() had never actually executed under
 * test. Since US4 (research R3/A6) ingest() never records success.
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

    /**
     * Research A6/R3: background ingest is never admitted through
     * isAvailable(), so it must not record success. A successful ingest
     * while the breaker is half-open leaves the state exactly as it was;
     * recovery is decided only by CircuitBreakerMiddleware's probes.
     */
    public function test_real_ingest_success_does_not_record_success_or_close_half_open_breaker(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->forceHalfOpen();
        $before = $this->breakerState();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $before['state']);

        $result = app(TransactionIngestService::class)->ingest($this->transactionPayload(
            'REAL-SUCCESS-'.Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame($before, $this->breakerState());
    }

    public function test_real_ingest_success_leaves_open_breaker_open(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->forceOpen();
        $before = $this->breakerState();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $before['state']);

        $result = app(TransactionIngestService::class)->ingest($this->transactionPayload(
            'REAL-OPEN-'.Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame($before, $this->breakerState());
    }

    public function test_real_ingest_success_while_closed_is_a_noop(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $result = app(TransactionIngestService::class)->ingest($this->transactionPayload(
            'REAL-CLOSED-'.Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame([], $this->breakerState());
    }

    public function test_receipt_duplicate_with_open_breaker_records_nothing_and_no_data_failure(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->transactionPayload(
            'REAL-DUP-'.Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        );
        $this->assertSame('accepted', app(TransactionIngestService::class)->ingest($payload)['status']);

        $this->forceOpen();
        $before = $this->breakerState();
        $metricBefore = (int) Metrics::get('ingest.data_failure_count', 0);

        // Same receipt + checksum: caught by the receipt pre-check.
        $result = app(TransactionIngestService::class)->ingest($payload);

        $this->assertSame('already_processed', $result['status']);
        $this->assertSame($before, $this->breakerState());
        $this->assertSame($metricBefore, (int) Metrics::get('ingest.data_failure_count', 0));
    }

    public function test_data_level_failure_with_half_open_breaker_records_neither_and_increments_metric(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->forceHalfOpen();
        $before = $this->breakerState();
        $metricBefore = (int) Metrics::get('ingest.data_failure_count', 0);

        $result = $this->throwingService('SQLSTATE[23000]: Integrity constraint violation: 1048 Column cannot be null')
            ->ingest($this->transactionPayload(
                'REAL-DATA-HO-'.Str::upper(Str::random(8)),
                $terminal->serial_number,
                $tenant->id,
                $terminal->id
            ));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('ingest_failed', $result['message']);
        $this->assertSame($before, $this->breakerState());
        $this->assertSame($metricBefore + 1, (int) Metrics::get('ingest.data_failure_count', 0));
    }

    public function test_classified_unique_violation_records_neither_and_increments_metric(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->forceHalfOpen();
        $before = $this->breakerState();
        $metricBefore = (int) Metrics::get('ingest.data_failure_count', 0);

        $service = new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class)) extends TransactionIngestService
        {
            protected function insertTransactionParent(array $parent): int
            {
                $errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'transactions.ux_transactions_refund_reference'"];
                $previous = new \PDOException('SQLSTATE[23000]: '.$errorInfo[2]);
                $previous->errorInfo = $errorInfo;

                throw new QueryException('mysql', 'insert into `transactions` ...', [], $previous);
            }
        };

        $result = $service->ingest($this->transactionPayload(
            'REAL-UNIQ-'.Str::upper(Str::random(8)),
            $terminal->serial_number,
            $tenant->id,
            $terminal->id
        ));

        $this->assertSame('unique_constraint_violation', $result['message']);
        $this->assertSame($before, $this->breakerState());
        $this->assertSame($metricBefore + 1, (int) Metrics::get('ingest.data_failure_count', 0));
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
        $this->assertSame(0, (int) Metrics::get('ingest.data_failure_count', 0));
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
        $this->assertSame(1, (int) Metrics::get('ingest.data_failure_count', 0));

        // No Redis write should have occurred at all — recordFailure() was
        // never invoked for this classification.
        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:' . CircuitBreaker::INGESTION_SERVICE_KEY);
        $this->assertSame([], $state);
    }

    private function forceOpen(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $breaker = new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY);
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();
    }

    private function forceHalfOpen(): void
    {
        $this->forceOpen();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));
        $this->assertTrue((new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY))->isAvailable());
    }

    private function breakerState(): array
    {
        return $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:'.CircuitBreaker::INGESTION_SERVICE_KEY);
    }

    private function throwingService(string $message): TransactionIngestService
    {
        return new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class), $message) extends TransactionIngestService
        {
            public function __construct(DeadlockRetryService $retry, ProviderTimestampNormalizer $normalizer, private string $message)
            {
                parent::__construct($retry, $normalizer);
            }

            protected function insertTransactionParent(array $parent): int
            {
                throw new QueryException('mysql', 'insert into transactions ...', [], new \PDOException($this->message, 0));
            }
        };
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
