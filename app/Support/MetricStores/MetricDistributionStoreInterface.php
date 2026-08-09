<?php

namespace App\Support\MetricStores;

/**
 * Storage contract for App\Support\Metrics::sample()/percentile() (WU2,
 * T053 foundation) — percentile-eligible observation sampling, backed by a
 * bounded Redis structure. See RedisMetricDistributionStore for the
 * concrete cap/TTL/cardinality-budget policy.
 */
interface MetricDistributionStoreInterface
{
    /**
     * Record one percentile-eligible observation for $name, optionally
     * scoped by an allowlisted set of dimensions (e.g. ['route' => '...']).
     * Never throws — a storage failure or a cardinality-budget rejection
     * silently drops the sample (logged at a bounded, low frequency by the
     * implementation), matching this codebase's existing metrics contract
     * that instrumentation must never break business flow.
     */
    public function sample(string $name, float $value, array $dims = []): void;

    /**
     * Read back a percentile for $name (0-100), scoped by the same
     * dimensions used at sample() time.
     *
     * @return array{value: float|null, sample_count: int} `value` is null (never
     *                                                     0) when there are zero eligible samples, so callers can tell "no data"
     *                                                     apart from "zero latency". `sample_count` is the number of samples the
     *                                                     value was computed from, so callers can gauge confidence in a
     *                                                     low-sample-count reading.
     */
    public function percentile(string $name, int $p, array $dims = []): array;
}
