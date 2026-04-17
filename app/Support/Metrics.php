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

    /**
     * Record a timing metric in milliseconds.
     * Keeps a rolling average of the last N samples or uses high-resolution snapshots.
     */
    public static function timing(string $name, float $ms): void
    {
        try {
            $key = self::key($name . ':avg');
            $countKey = self::key($name . ':count');

            // Simple rolling average: (avg * count + new) / (count + 1)
            $avg = (float) Cache::get($key, 0);
            $count = (int) Cache::get($countKey, 0);

            $newAvg = (($avg * $count) + $ms) / ($count + 1);

            Cache::put($key, $newAvg, 3600);
            Cache::increment($countKey);
        } catch (\Throwable $e) {
            // Swallow
        }
    }

    /**
     * Record a value into a 1-minute time bucket for charting.
     */
    public static function bucket(string $name, float $value): void
    {
        try {
            $minute = now()->format('Y-m-d H:i');
            $key = self::key("buckets:{$name}:{$minute}");
            
            // Store the average for the minute
            $avg = (float) Cache::get($key, 0);
            $countKey = $key . ':count';
            $count = (int) Cache::get($countKey, 0);
            
            $newAvg = (($avg * $count) + $value) / ($count + 1);
            
            Cache::put($key, $newAvg, 86400); // Keep for 24h
            Cache::increment($countKey);
        } catch (\Throwable $e) {
            // Swallow
        }
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
}
