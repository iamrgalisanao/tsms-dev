<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Ultra-lightweight in-process counters using Cache. Not a full metrics system,
 * but provides quick visibility until Prometheus / StatsD is integrated.
 */
class Metrics
{
    private static function key(string $name): string
    {
        return 'metrics:'.$name;
    }

    public static function incr(string $name, int $by = 1): void
    {
        $key = self::key($name);
        try {
            Cache::increment($key, $by);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public static function decr(string $name, int $by = 1): void
    {
        $key = self::key($name);
        try {
            Cache::decrement($key, $by);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public static function timing(string $name, float|int $value): void
    {
        self::setGauge($name, $value);
    }

    public static function bucket(string $name, float|int $value): void
    {
        self::setGauge($name.'.last_bucket', $value);
    }

    public static function get(string $name, $default = 0)
    {
        return Cache::get(self::key($name), $default);
    }

    public static function snapshot(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = self::get($n, 0);
        }
        return $out;
    }

    private static function setGauge(string $name, float|int $value): void
    {
        try {
            Cache::put(self::key($name), $value);
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }
}
