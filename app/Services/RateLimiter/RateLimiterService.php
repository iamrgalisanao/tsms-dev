<?php

namespace App\Services\RateLimiter;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

class RateLimiterService
{
    protected $limiter;
    protected $monitor;

    public function __construct(RateLimiter $limiter, RateLimitMonitor $monitor)
    {
        $this->limiter = $limiter;
        $this->monitor = $monitor;
    }

    public function resolveRequestSignature(Request $request, string $type = 'api'): string
    {
        $signature = $request->ip();
        
        if ($user = $request->user()) {
            $signature .= '|' . $user->id;
        }

        if ($tenantId = $request->header('X-Tenant-ID')) {
            $signature .= '|tenant:' . $tenantId;
        }

        return sha1($signature . '|' . $type);
    // $monitor property and constructor already defined above, this duplicate block is removed.
        $this->monitor = $monitor;
    }

    public function attemptRequest(Request $request, string $type = 'api'): bool
    {
        $key = $this->resolveRequestSignature($request, $type);
        $config = Config::get("rate-limiting.default_limits.{$type}");
        
        if ($this->limiter->tooManyAttempts($key, $config['attempts'])) {
            // Record violation for monitoring
            $this->monitor->recordViolation($type, [
                'ip' => $request->ip(),
                'user_id' => $request->user()?->id,
                'tenant_id' => $request->header('X-Tenant-ID'),
                'endpoint' => $request->path()
            ]);
            return false;
        }

        $this->limiter->hit($key, $config['decay_minutes'] * 60);
        return true;
    }

    public function getRemainingAttempts(Request $request, string $type = 'api'): int
    {
        $key = $this->resolveRequestSignature($request, $type);
        $config = Config::get("rate-limiting.default_limits.{$type}");
        
        return $this->limiter->remaining($key, $config['attempts']);
    }

    public function clearAttempts(Request $request, string $type = 'api'): void
    {
        $key = $this->resolveRequestSignature($request, $type);
        $this->limiter->clear($key);
    }
}
