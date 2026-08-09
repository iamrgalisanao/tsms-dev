<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CircuitBreaker;
use App\Support\Metrics;
use App\Support\MetricStores\MetricStoreInterface;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;
use Tests\Traits\FakesMetricsDistributionRedis;
use Tests\Traits\FakesSkewRankingRedis;

/**
 * WU7 (T054, specs/001-100-tenant-resilience/plan.md) controller tests for
 * the eight new read-only ingestion observability endpoints added to
 * ObservabilityController. Covers, per the plan's requirement:
 *   - authorization (abilities:admin:manage gate),
 *   - available-state envelope shape with real data,
 *   - degraded/unavailable state for both distinct backing mechanisms
 *     (Redis-backed and Cache-backed),
 *   - bounded top-N clamping on the skew endpoint,
 *   - read-only enforcement (no non-GET verb is routed).
 *
 * Shard counts are pinned to 1 (and the VIP lane disabled) in setUp() so
 * each test only has to reason about a single intake/processing queue
 * pair, rather than the config-default 8 shards each.
 */
class ObservabilityIngestionEndpointsTest extends TestCase
{
    use FakesCircuitBreakerRedis;
    use FakesMetricsDistributionRedis;
    use FakesSkewRankingRedis;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.intake.shard_count', 1);
        config()->set('tsms.processing.shard_count', 1);
        config()->set('tsms.intake.vip_shard', '');
        config()->set('tsms.intake.backpressure.max_queue_depth', 5000);
        config()->set('tsms.intake.backpressure.mode', 'observe');
        config()->set('tsms.metrics.skew.max_top_n', 100);
    }

    protected function tearDown(): void
    {
        Metrics::swapStore(null);
        Mockery::close();
        parent::tearDown();
    }

    /**
     * A MetricStoreInterface double whose get() throws, simulating the
     * Cache-backed counter store being unreachable — using Metrics's own
     * swapStore() test seam rather than mocking the global Cache facade
     * directly, since the 'api' middleware group's bare ThrottleRequests
     * middleware also reads/writes the same Cache facade on every request
     * (for its own hit-counting), and a blanket Cache::shouldReceive('get')
     * mock would incorrectly intercept that unrelated call too.
     */
    private function breakMetricsCounterStore(): void
    {
        $store = Mockery::mock(MetricStoreInterface::class);
        $store->shouldReceive('get')->andThrow(new \RuntimeException('Cache store unreachable'));
        Metrics::swapStore($store);
    }

    /** @return list<string> every new WU7 route path, relative to /api/v1/observability */
    private function newRoutes(): array
    {
        return [
            'ingestion/queue-depth',
            'ingestion/queue-age',
            'ingestion/circuit-breaker',
            'ingestion/backpressure',
            'ingestion/db-pressure',
            'ingestion/skew',
            'ingestion/rejections',
            'ingestion/percentiles',
        ];
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['admin:manage']);

        return $user;
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_every_new_endpoint_rejects_an_unauthenticated_request(): void
    {
        foreach ($this->newRoutes() as $route) {
            $this->getJson("/api/v1/observability/{$route}")
                ->assertStatus(401);
        }
    }

    public function test_every_new_endpoint_rejects_a_token_without_admin_manage_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['transaction:read']);

        foreach ($this->newRoutes() as $route) {
            $this->getJson("/api/v1/observability/{$route}")
                ->assertStatus(403);
        }
    }

    // Note: there is no single combined "every endpoint admits an
    // authorized request" test here. Each endpoint's own available-state
    // test below already exercises actingAsAdmin() + a 200 response with
    // real data, which proves admission at least as strongly as a combined
    // loop would — and avoids registering several competing
    // Redis::shouldReceive('connection')->with('default') fakes (one per
    // service under test) against the same facade call in a single test,
    // which is exactly the "racing doubles" hazard
    // FakesCircuitBreakerRedis::fakeCircuitBreakerRedisDouble()'s own
    // doc-comment warns about.

    // ------------------------------------------------------------------
    // Read-only enforcement (Architecture Invariant 6)
    // ------------------------------------------------------------------

    public function test_every_new_endpoint_is_unreachable_via_non_get_verbs(): void
    {
        $this->actingAsAdmin();

        foreach ($this->newRoutes() as $route) {
            $url = "/api/v1/observability/{$route}";

            $this->postJson($url)->assertStatus(405);
            $this->putJson($url)->assertStatus(405);
            $this->deleteJson($url)->assertStatus(405);
        }
    }

    // ------------------------------------------------------------------
    // Envelope shape helper
    // ------------------------------------------------------------------

    private function assertEnvelopeShape(array $json): void
    {
        foreach (['generated_at', 'source', 'window', 'freshness_seconds', 'status', 'data'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertContains($json['status'], ['available', 'degraded', 'unavailable']);
    }

    // ------------------------------------------------------------------
    // 1. Queue depth
    // ------------------------------------------------------------------

    public function test_queue_depth_returns_available_with_real_per_shard_depths(): void
    {
        $this->actingAsAdmin();

        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->with('queues:transaction-intake:s0')->andReturn(3);
        $redis->shouldReceive('llen')->with('queues:transaction-processing:s0')->andReturn(7);
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $response = $this->getJson('/api/v1/observability/ingestion/queue-depth');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertSame('redis', $json['source']);
        $this->assertSame(3, $json['data']['intake']['shards'][0]['depth']);
        $this->assertSame('transaction-intake:s0', $json['data']['intake']['shards'][0]['queue']);
        $this->assertSame(7, $json['data']['processing']['shards'][0]['depth']);
    }

    public function test_queue_depth_is_unavailable_and_does_not_500_when_redis_throws(): void
    {
        $this->actingAsAdmin();

        Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new \RuntimeException('Redis down'));

        $response = $this->getJson('/api/v1/observability/ingestion/queue-depth');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('unavailable', $json['status']);
    }

    public function test_queue_depth_is_degraded_when_only_some_shards_are_reachable(): void
    {
        $this->actingAsAdmin();

        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->with('queues:transaction-intake:s0')->andReturn(2);
        $redis->shouldReceive('llen')->with('queues:transaction-processing:s0')->andThrow(new \RuntimeException('Redis down'));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $response = $this->getJson('/api/v1/observability/ingestion/queue-depth');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame('degraded', $json['status']);
        $this->assertTrue($json['data']['intake']['shards'][0]['available']);
        $this->assertFalse($json['data']['processing']['shards'][0]['available']);
    }

    // ------------------------------------------------------------------
    // 2. Queue age
    // ------------------------------------------------------------------

    public function test_queue_age_returns_available_with_real_ages(): void
    {
        $this->actingAsAdmin();

        $pushedAt = (string) (now()->getPreciseTimestamp(3) / 1000 - 42.0);

        $redis = Mockery::mock();
        $redis->shouldReceive('lindex')->with('queues:transaction-intake:s0', 0)
            ->andReturn(json_encode(['pushedAt' => $pushedAt]));
        $redis->shouldReceive('lindex')->with('queues:transaction-processing:s0', 0)
            ->andReturn(null);
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $response = $this->getJson('/api/v1/observability/ingestion/queue-age');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertTrue($json['data']['intake']['shards'][0]['available']);
        $this->assertEqualsWithDelta(42.0, $json['data']['intake']['shards'][0]['age_seconds'], 0.5);
        // assertEquals (not assertSame): json_encode() renders 0.0 as "0",
        // which json_decode() reads back as int(0) — the same PHP JSON
        // round-trip quirk noted in ObservabilityLatencyMetricsTest.php.
        $this->assertEquals(0.0, $json['data']['processing']['shards'][0]['age_seconds']);
    }

    public function test_queue_age_is_unavailable_when_redis_throws(): void
    {
        $this->actingAsAdmin();

        Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new \RuntimeException('Redis down'));

        $response = $this->getJson('/api/v1/observability/ingestion/queue-age');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame('unavailable', $json['status']);
    }

    // ------------------------------------------------------------------
    // 3. Circuit breaker state
    // ------------------------------------------------------------------

    public function test_circuit_breaker_state_returns_live_and_mirrored_values(): void
    {
        $this->actingAsAdmin();
        $this->fakeCircuitBreakerRedis();

        Metrics::timing('circuit_breaker.state.'.CircuitBreaker::INGESTION_SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED);

        $response = $this->getJson('/api/v1/observability/ingestion/circuit-breaker');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertSame(CircuitBreaker::INGESTION_SERVICE_KEY, $json['data']['service']);
        $this->assertSame('closed', $json['data']['live']['state']);
        $this->assertSame(0, $json['data']['metrics_mirrored']['state_code']);
        $this->assertSame('closed', $json['data']['metrics_mirrored']['state_label']);
    }

    public function test_circuit_breaker_state_is_unavailable_when_redis_throws(): void
    {
        $this->actingAsAdmin();
        $this->breakCircuitBreakerRedisConnection();

        $response = $this->getJson('/api/v1/observability/ingestion/circuit-breaker');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame('unavailable', $json['status']);
    }

    public function test_circuit_breaker_state_never_calls_is_available_and_therefore_never_admits_a_probe(): void
    {
        // Regression guard: this endpoint must read state without ever
        // consuming a half-open probe slot as a side effect of being
        // observed (Architecture Invariant 6). Seed a half-open breaker
        // with a full probe count, then confirm reading it via the
        // endpoint does not push half_open_probe_count any higher.
        $this->actingAsAdmin();
        $this->fakeCircuitBreakerRedis();

        $key = config('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:').CircuitBreaker::INGESTION_SERVICE_KEY;
        Redis::connection('default')->hset($key, 'state', 'half-open');
        Redis::connection('default')->hset($key, 'half_open_probe_count', 3);
        Redis::connection('default')->hset($key, 'half_open_generation', 1);

        $this->getJson('/api/v1/observability/ingestion/circuit-breaker')->assertStatus(200);

        $this->assertSame('3', $this->fakeCircuitBreakerRedisState($key)['half_open_probe_count']);
    }

    // ------------------------------------------------------------------
    // 4. Backpressure state
    // ------------------------------------------------------------------

    public function test_backpressure_state_returns_config_and_depth_summary(): void
    {
        $this->actingAsAdmin();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 100);

        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->with('queues:transaction-intake:s0')->andReturn(10);
        $redis->shouldReceive('llen')->with('queues:transaction-processing:s0')->andReturn(20);
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $response = $this->getJson('/api/v1/observability/ingestion/backpressure');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertTrue($json['data']['enabled']);
        $this->assertSame('enforce', $json['data']['mode']);
        $this->assertSame(100, $json['data']['threshold']);
        $this->assertSame(10, $json['data']['intake_max_depth']);
        $this->assertSame(20, $json['data']['processing_max_depth']);
    }

    // ------------------------------------------------------------------
    // 5. DB pressure
    // ------------------------------------------------------------------

    public function test_db_pressure_returns_counters_and_percentiles(): void
    {
        $this->actingAsAdmin();
        $this->fakeMetricsDistributionRedis();

        Metrics::incr('db.deadlock_retry.attempted');
        Metrics::incr('db.deadlock_retry.attempted');
        Metrics::incr('db.deadlock_retry.succeeded');
        Metrics::sample('db.deadlock_retry.delay_ms', 50.0);
        Metrics::sample('db.deadlock_retry.delay_ms', 150.0);

        $response = $this->getJson('/api/v1/observability/ingestion/db-pressure');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertSame(2, $json['data']['counters']['attempted']);
        $this->assertSame(1, $json['data']['counters']['succeeded']);
        $this->assertSame(0, $json['data']['counters']['exhausted']);
        $this->assertNotNull($json['data']['delay_ms']['p95']['value']);
    }

    public function test_db_pressure_reports_null_percentiles_honestly_when_no_samples_exist_yet(): void
    {
        $this->actingAsAdmin();
        $this->fakeMetricsDistributionRedis();

        $response = $this->getJson('/api/v1/observability/ingestion/db-pressure');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame('available', $json['status']);
        $this->assertSame(0, $json['data']['counters']['attempted']);
        $this->assertNull($json['data']['delay_ms']['p50']['value']);
        $this->assertSame(0, $json['data']['delay_ms']['p50']['sample_count']);
    }

    public function test_db_pressure_is_unavailable_when_the_cache_backed_counter_store_throws(): void
    {
        $this->actingAsAdmin();
        $this->breakMetricsCounterStore();

        $response = $this->getJson('/api/v1/observability/ingestion/db-pressure');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('unavailable', $json['status']);
        $this->assertSame('application', $json['source']);
    }

    // ------------------------------------------------------------------
    // 6. Tenant/terminal skew (bounded top-N)
    // ------------------------------------------------------------------

    public function test_skew_returns_available_with_top_n_ranking(): void
    {
        $this->actingAsAdmin();
        $this->fakeSkewRankingRedis();

        app(\App\Services\SkewRankingService::class)->recordTenant(101, 5);
        app(\App\Services\SkewRankingService::class)->recordTenant(102, 1);
        app(\App\Services\SkewRankingService::class)->recordTerminal(9001, 3);

        $response = $this->getJson('/api/v1/observability/ingestion/skew?limit=10');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertSame(10, $json['data']['requested_limit']);
        $this->assertSame('101', $json['data']['tenants'][0]['member']);
        $this->assertSame('9001', $json['data']['terminals'][0]['member']);
    }

    public function test_skew_limit_is_clamped_to_max_top_n_and_not_passed_through_unbounded(): void
    {
        config()->set('tsms.metrics.skew.max_top_n', 2);
        $this->actingAsAdmin();
        $this->fakeSkewRankingRedis();

        $skew = app(\App\Services\SkewRankingService::class);
        foreach ([1, 2, 3, 4, 5] as $tenantId) {
            $skew->recordTenant($tenantId);
        }

        $response = $this->getJson('/api/v1/observability/ingestion/skew?limit=1000');

        $response->assertStatus(200);
        $json = $response->json();
        // requested_limit reflects the raw, unclamped input for operator visibility...
        $this->assertSame(1000, $json['data']['requested_limit']);
        // ...but the actual returned ranking is clamped server-side to max_top_n (2),
        // never passed through unbounded to the underlying ZSET read.
        $this->assertCount(2, $json['data']['tenants']);
    }

    // ------------------------------------------------------------------
    // 7. Rejection reasons
    // ------------------------------------------------------------------

    public function test_rejection_reasons_returns_all_six_known_counters(): void
    {
        $this->actingAsAdmin();

        Metrics::incr('ingestion.rejected.payload_size');
        Metrics::incr('ingestion.rejected.payload_size');
        Metrics::incr('ingestion.rejected.fairness');

        $response = $this->getJson('/api/v1/observability/ingestion/rejections');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertSame(2, $json['data']['reasons']['payload_size']);
        $this->assertSame(1, $json['data']['reasons']['fairness']);
        $this->assertSame(0, $json['data']['reasons']['batch_size']);
        $this->assertSame(0, $json['data']['reasons']['backpressure']);
        $this->assertSame(0, $json['data']['reasons']['circuit_breaker']);
        $this->assertSame(0, $json['data']['reasons']['tenant_override']);
        $this->assertSame(3, $json['data']['total']);
    }

    public function test_rejection_reasons_is_unavailable_when_the_cache_backed_store_throws(): void
    {
        $this->actingAsAdmin();
        $this->breakMetricsCounterStore();

        $response = $this->getJson('/api/v1/observability/ingestion/rejections');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('unavailable', $json['status']);
    }

    // ------------------------------------------------------------------
    // 8. Percentile metrics
    // ------------------------------------------------------------------

    public function test_percentile_metrics_returns_the_known_db_deadlock_retry_distributions(): void
    {
        $this->actingAsAdmin();
        $this->fakeMetricsDistributionRedis();

        Metrics::sample('db.deadlock_retry.operation_ms', 10.0);
        Metrics::sample('db.deadlock_retry.operation_ms', 20.0);

        $response = $this->getJson('/api/v1/observability/ingestion/percentiles');

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertEnvelopeShape($json);
        $this->assertSame('available', $json['status']);
        $this->assertArrayHasKey('db.deadlock_retry.delay_ms', $json['data']['metrics']);
        $this->assertArrayHasKey('db.deadlock_retry.operation_ms', $json['data']['metrics']);
        $this->assertArrayHasKey('db.deadlock_retry.total_recovery_ms', $json['data']['metrics']);
        $this->assertNotNull($json['data']['metrics']['db.deadlock_retry.operation_ms']['p50']['value']);
        // No samples recorded for delay_ms in this test: null value, not 0,
        // per Metrics::percentile()'s "no data" vs "zero latency" contract.
        $this->assertNull($json['data']['metrics']['db.deadlock_retry.delay_ms']['p50']['value']);
    }
}
