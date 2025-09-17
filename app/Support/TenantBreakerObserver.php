<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

/**
 * Phase 1 (observation-only) per-tenant circuit breaker metrics.
 *
 * Tracks attempt + retryable failure counters per tenant in a sliding window.
 * No enforcement – only logs candidates when thresholds exceeded.
 */
class TenantBreakerObserver
{
    private bool $enabled;
    private int $minRequests;
    private float $failureRatioThreshold;
    private int $windowMinutes;

    public function __construct()
    {
        $cfg = config('tsms.tenant_breaker.observation');
        $this->enabled = (bool) ($cfg['enabled'] ?? false);
        $this->minRequests = (int) ($cfg['min_requests'] ?? 20);
        $this->failureRatioThreshold = (float) ($cfg['failure_ratio_threshold'] ?? 0.5);
        $this->windowMinutes = (int) ($cfg['time_window_minutes'] ?? 10);
    }

    private function baseKey(int $tenantId): string
    {
        return 'tenant_breaker:obs:'.$tenantId;
    }

    private function expiry(): int
    {
        return $this->windowMinutes * 60; // seconds
    }

    public function recordAttempt(?int $tenantId): void
    {
        if (!$this->enabled || !$tenantId) { return; }
        $k = $this->baseKey($tenantId).':attempts';
        Cache::add($k, 0, $this->expiry());
        Cache::increment($k);
    }

    public function recordRetryableFailure(?int $tenantId): void
    {
        if (!$this->enabled || !$tenantId) { return; }
        $k = $this->baseKey($tenantId).':failures';
        Cache::add($k, 0, $this->expiry());
        Cache::increment($k);
    }

    public function evaluate(?int $tenantId): ?array
    {
        if (!$this->enabled || !$tenantId) { return null; }
        $attempts = (int) Cache::get($this->baseKey($tenantId).':attempts', 0);
        $failures = (int) Cache::get($this->baseKey($tenantId).':failures', 0);
        if ($attempts < max(1, $this->minRequests)) {
            return [
                'eligible' => false,
                'attempts' => $attempts,
                'failures' => $failures,
                'failure_ratio' => $attempts ? ($failures / $attempts) : 0.0,
            ];
        }
        $ratio = $attempts ? ($failures / $attempts) : 0.0;
        $over = $ratio >= $this->failureRatioThreshold;
        return [
            'eligible' => true,
            'over_threshold' => $over,
            'attempts' => $attempts,
            'failures' => $failures,
            'failure_ratio' => $ratio,
            'min_requests' => $this->minRequests,
            'failure_ratio_threshold' => $this->failureRatioThreshold,
            'window_minutes' => $this->windowMinutes,
        ];
    }
}
