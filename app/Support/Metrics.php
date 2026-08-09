<?php

namespace App\Support;

use App\Support\MetricStores\CacheMetricStore;
use App\Support\MetricStores\MetricDistributionStoreInterface;
use App\Support\MetricStores\MetricStoreInterface;
use App\Support\MetricStores\RedisMetricDistributionStore;

/**
 * Ultra-lightweight in-process counters/gauges, plus (WU2, T053
 * foundation) bounded percentile-distribution sampling. Not a full metrics
 * system, but provides quick visibility until Prometheus / StatsD is
 * integrated.
 *
 * Storage-interface split (do not bolt Redis-specific operations onto this
 * facade, and do not force counters/gauges onto a Redis-specific API):
 *
 *   Metrics (this class — unchanged incr/decr/timing/bucket/get/snapshot
 *            API, plus the new sample/percentile API)
 *       |
 *       +-- MetricStoreInterface (counter/gauge)  -> CacheMetricStore
 *       +-- MetricDistributionStoreInterface       -> RedisMetricDistributionStore
 *           (percentile sampling)
 *
 * incr/decr/timing/bucket/get/snapshot are unchanged in signature and
 * behavior from the pre-WU2 implementation — they are simply delegated to
 * CacheMetricStore now instead of calling Cache:: directly.
 */
class Metrics
{
    protected static ?MetricStoreInterface $storeOverride = null;

    protected static ?MetricDistributionStoreInterface $distributionStoreOverride = null;

    public static function incr(string $name, int $by = 1): void
    {
        static::store()->increment($name, $by);
    }

    public static function decr(string $name, int $by = 1): void
    {
        static::store()->decrement($name, $by);
    }

    public static function timing(string $name, float|int $value): void
    {
        static::store()->putGauge($name, $value);
    }

    public static function bucket(string $name, float|int $value): void
    {
        static::store()->putGauge($name.'.last_bucket', $value);
    }

    public static function get(string $name, $default = 0)
    {
        return static::store()->get($name, $default);
    }

    public static function snapshot(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = static::get($n, 0);
        }

        return $out;
    }

    /**
     * Record one percentile-eligible observation. $dims is restricted to a
     * fixed allowlist (currently 'route', 'shard' — see
     * config/tsms.php's tsms.metrics.distribution.allowed_dimensions) by
     * the underlying store; unlisted keys are silently dropped rather than
     * accepted, so callers cannot accidentally grow unbounded cardinality
     * (e.g. by keying on tenant_id).
     */
    public static function sample(string $name, float|int $value, array $dims = []): void
    {
        static::distributionStore()->sample($name, (float) $value, $dims);
    }

    /**
     * Read a percentile (0-100) for a previously-sampled metric.
     *
     * @return array{value: float|null, sample_count: int} `value` is null when
     *                                                     there are zero samples — never 0 — so callers can distinguish "no
     *                                                     data" from "zero latency". `sample_count` tells the caller how many
     *                                                     samples backed the reading, as a confidence signal.
     */
    public static function percentile(string $name, int $p, array $dims = []): array
    {
        return static::distributionStore()->percentile($name, $p, $dims);
    }

    /**
     * Resolves a fresh CacheMetricStore per call (unless overridden by
     * swapStore(), for tests) rather than caching a singleton instance —
     * matching this codebase's existing CircuitBreaker convention of
     * reading config fresh per use instead of risking a stale cached
     * instance surviving a config change (e.g. between test cases).
     */
    public static function store(): MetricStoreInterface
    {
        return static::$storeOverride ?? new CacheMetricStore;
    }

    public static function distributionStore(): MetricDistributionStoreInterface
    {
        return static::$distributionStoreOverride ?? new RedisMetricDistributionStore;
    }

    /** Test seam: override the counter/gauge store. Pass null to restore the default. */
    public static function swapStore(?MetricStoreInterface $store): void
    {
        static::$storeOverride = $store;
    }

    /** Test seam: override the distribution store. Pass null to restore the default. */
    public static function swapDistributionStore(?MetricDistributionStoreInterface $store): void
    {
        static::$distributionStoreOverride = $store;
    }
}
