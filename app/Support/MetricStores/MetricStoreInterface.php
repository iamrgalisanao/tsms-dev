<?php

namespace App\Support\MetricStores;

/**
 * Storage contract for App\Support\Metrics's counter/gauge surface
 * (incr/decr/timing/bucket/get/snapshot). Kept separate from
 * MetricDistributionStoreInterface (WU2, T053 foundation) because the two
 * concerns have genuinely different shapes — single-value counters/gauges
 * here vs. percentile-eligible sample sets there — rather than forcing both
 * into one generic interface with untyped methods.
 */
interface MetricStoreInterface
{
    public function increment(string $name, int $by = 1): void;

    public function decrement(string $name, int $by = 1): void;

    public function putGauge(string $name, float|int $value): void;

    public function get(string $name, mixed $default = 0): mixed;
}
