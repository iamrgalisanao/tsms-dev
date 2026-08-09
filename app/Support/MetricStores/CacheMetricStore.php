<?php

namespace App\Support\MetricStores;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed counter/gauge store. This is a direct extraction of
 * App\Support\Metrics's pre-WU2 implementation (same key prefix, same
 * Cache::increment/decrement/put/get calls, same "swallow errors" contract)
 * — behavior is unchanged, only relocated behind MetricStoreInterface so the
 * facade can also route to a distinct distribution store (WU2, T053
 * foundation) without bolting Redis-specific operations onto this class.
 */
class CacheMetricStore implements MetricStoreInterface
{
    private function key(string $name): string
    {
        return 'metrics:'.$name;
    }

    public function increment(string $name, int $by = 1): void
    {
        try {
            Cache::increment($this->key($name), $by);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public function decrement(string $name, int $by = 1): void
    {
        try {
            Cache::decrement($this->key($name), $by);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public function putGauge(string $name, float|int $value): void
    {
        try {
            Cache::put($this->key($name), $value);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public function get(string $name, mixed $default = 0): mixed
    {
        return Cache::get($this->key($name), $default);
    }
}
