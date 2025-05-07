<?php

namespace App\Services\RateLimiter;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RateLimitMonitor
{
    public function recordViolation(string $type, array $context): void
    {
        // Log the violation
        Log::channel('rate-limits')->warning("Rate limit exceeded", [
            'type' => $type,
            'ip' => $context['ip'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'timestamp' => now(),
        ]);

        // Increment violation counter in Redis
        $key = "rate_limits:violations:{$type}:" . now()->format('Y-m-d:H');
        Cache::increment($key);
        Cache::expire($key, now()->addDay());
    }

    public function getViolationMetrics(string $type, int $hours = 24): array
    {
        $metrics = [];
        $now = now();

        for ($i = 0; $i < $hours; $i++) {
            $timestamp = $now->copy()->subHours($i);
            $key = "rate_limits:violations:{$type}:" . $timestamp->format('Y-m-d:H');
            $metrics[$timestamp->format('Y-m-d H:00')] = Cache::get($key, 0);
        }

        return $metrics;
    }
}
