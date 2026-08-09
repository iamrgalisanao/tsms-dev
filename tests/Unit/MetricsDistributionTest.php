<?php

namespace Tests\Unit;

use App\Support\Metrics;
use Tests\TestCase;
use Tests\Traits\FakesMetricsDistributionRedis;

/**
 * Unit tests for App\Support\Metrics::sample()/percentile() and the
 * bounded Redis structures behind them, backed by
 * App\Support\MetricStores\RedisMetricDistributionStore (WU2, T053
 * foundation). Uses the fake in-memory Redis double from
 * FakesMetricsDistributionRedis (mirroring RedisCircuitBreakerTest's use
 * of FakesCircuitBreakerRedis) since CI provisions no real Redis service.
 *
 * Does not touch the pre-existing Cache-backed
 * incr/decr/timing/bucket/get/snapshot API's own storage — see
 * test_legacy_counter_gauge_api_is_unchanged() below for that regression
 * coverage, which runs against the real (array-driver) Cache facade.
 */
class MetricsDistributionTest extends TestCase
{
    use FakesMetricsDistributionRedis;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.metrics.distribution.redis_connection', 'default');
        config()->set('tsms.metrics.distribution.key_prefix', 'metrics:dist:');
        config()->set('tsms.metrics.distribution.sample_cap', 1000);
        config()->set('tsms.metrics.distribution.sample_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.cardinality_budget', 200);
        config()->set('tsms.metrics.distribution.cardinality_ttl_seconds', 3600);
        config()->set('tsms.metrics.distribution.allowed_dimensions', ['route', 'shard']);

        $this->fakeMetricsDistributionRedis();
    }

    /**
     * Known-dataset nearest-rank round-trip: 10 samples (1..10), hand-computed
     * expected percentiles for rank = ceil(p/100 * n), 1-indexed.
     *   p50: rank = ceil(0.50*10) = 5  -> 5th smallest  -> 5
     *   p95: rank = ceil(0.95*10) = 10 -> 10th smallest -> 10
     *   p99: rank = ceil(0.99*10) = 10 -> 10th smallest -> 10
     */
    public function test_sample_and_percentile_round_trip_nearest_rank(): void
    {
        foreach (range(1, 10) as $value) {
            Metrics::sample('test.latency_ms', (float) $value);
        }

        $p50 = Metrics::percentile('test.latency_ms', 50);
        $p95 = Metrics::percentile('test.latency_ms', 95);
        $p99 = Metrics::percentile('test.latency_ms', 99);

        $this->assertSame(5.0, $p50['value']);
        $this->assertSame(10, $p50['sample_count']);

        $this->assertSame(10.0, $p95['value']);
        $this->assertSame(10, $p95['sample_count']);

        $this->assertSame(10.0, $p99['value']);
        $this->assertSame(10, $p99['sample_count']);
    }

    /** Explicitly required by the WU2 plan: zero samples must read back null, not 0. */
    public function test_percentile_with_zero_samples_returns_null_not_zero(): void
    {
        $result = Metrics::percentile('test.never_sampled', 50);

        $this->assertNull($result['value']);
        $this->assertSame(0, $result['sample_count']);
    }

    public function test_sample_count_cap_bounds_the_underlying_zset(): void
    {
        config()->set('tsms.metrics.distribution.sample_cap', 5);

        foreach (range(1, 10) as $value) {
            Metrics::sample('test.capped', (float) $value);
        }

        $key = 'metrics:dist:test.capped:none';
        $this->assertSame(5, $this->fakeMetricsZsetSize($key));

        // Oldest-first eviction: the 5 most-recently-inserted values (6..10)
        // should be the ones retained.
        $min = Metrics::percentile('test.capped', 0);
        $max = Metrics::percentile('test.capped', 100);
        $this->assertSame(6.0, $min['value']);
        $this->assertSame(10.0, $max['value']);
    }

    public function test_cardinality_budget_rejects_combinations_past_the_cap(): void
    {
        config()->set('tsms.metrics.distribution.cardinality_budget', 3);

        foreach (['a', 'b', 'c', 'd'] as $route) {
            Metrics::sample('test.cardinality', 1.0, ['route' => $route]);
        }

        $combosKey = 'metrics:dist:test.cardinality:combos';
        $this->assertSame(3, $this->fakeMetricsSetSize($combosKey));

        $sampleKeys = array_filter(
            array_keys($this->fakeMetricsZsetStore),
            fn (string $key) => str_starts_with($key, 'metrics:dist:test.cardinality:') && $key !== $combosKey
        );
        $this->assertCount(3, $sampleKeys, 'the 4th distinct dimension combination must not create its own sample key');
    }

    /** Dimension order must not affect the resulting storage key (canonicalization). */
    public function test_dimension_key_order_is_canonicalized(): void
    {
        Metrics::sample('test.canon', 7.0, ['route' => 'a', 'shard' => 'b']);
        Metrics::sample('test.canon', 9.0, ['shard' => 'b', 'route' => 'a']);

        $result = Metrics::percentile('test.canon', 50, ['route' => 'a', 'shard' => 'b']);

        $this->assertSame(2, $result['sample_count'], 'both samples must have landed under the same canonical key');
    }

    /** Dimension keys outside the allowlist (e.g. tenant_id) are silently dropped, not stored verbatim. */
    public function test_disallowed_dimension_keys_are_ignored(): void
    {
        Metrics::sample('test.allowlist', 3.0, ['tenant_id' => '12345']);
        Metrics::sample('test.allowlist', 4.0, []);

        $result = Metrics::percentile('test.allowlist', 50, ['tenant_id' => '99999']);

        // Both samples land in the same "no allowed dimensions" bucket,
        // since tenant_id is not in the allowlist and is stripped from both.
        $this->assertSame(2, $result['sample_count']);
    }

    /**
     * The pre-existing Cache-backed counter/gauge API must behave exactly as
     * before the WU2 storage-interface split — this runs against the real
     * array-driver Cache facade (see phpunit.xml's CACHE_DRIVER=array), not
     * the fake Redis double, since incr/decr/timing/bucket/get/snapshot are
     * untouched by the new distribution store.
     */
    public function test_legacy_counter_gauge_api_is_unchanged(): void
    {
        Metrics::incr('legacy.counter');
        Metrics::incr('legacy.counter', 4);
        $this->assertEquals(5, Metrics::get('legacy.counter'));

        Metrics::decr('legacy.counter', 2);
        $this->assertEquals(3, Metrics::get('legacy.counter'));

        Metrics::timing('legacy.latency', 123.4);
        $this->assertEquals(123.4, Metrics::get('legacy.latency'));

        Metrics::bucket('legacy.latency', 55);
        $this->assertEquals(55, Metrics::get('legacy.latency.last_bucket'));

        $this->assertSame([
            'legacy.counter' => 3,
            'legacy.latency' => 123.4,
            'legacy.missing' => 0,
        ], Metrics::snapshot(['legacy.counter', 'legacy.latency', 'legacy.missing']));
    }
}
