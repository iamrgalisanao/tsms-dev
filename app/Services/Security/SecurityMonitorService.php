<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityAlertRule;
use App\Services\Security\Contracts\SecurityMonitorInterface;
use App\Services\Security\Contracts\SecurityAlertHandlerInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SecurityMonitorService implements SecurityMonitorInterface
{
    private SecurityAlertHandlerInterface $alertHandler;

    public function __construct(SecurityAlertHandlerInterface $alertHandler)
    {
        $this->alertHandler = $alertHandler;
    }    public function recordEvent(
        string $eventType,
        string $severity,
        array $context = [],
        ?string $sourceIp = null,
        ?int $userId = null
    ): void 
    {
        try {
            // Get tenant ID from context or current session
            $tenantId = $context['tenant_id'] ?? \Illuminate\Support\Facades\Auth::user()?->tenant_id ?? 1;  // Default to tenant 1 for testing
            
            // Create the security event
            $event = SecurityEvent::create([
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'severity' => $severity,
                'user_id' => $userId,
                'source_ip' => $sourceIp,
                'context' => $context,
                'event_timestamp' => now()
            ]);

            // Check if any alert rules are triggered
            if ($this->checkAlertRules($tenantId, $eventType)) {
                Log::warning('Security alert rule triggered', [
                    'event_id' => $event->id,
                    'event_type' => $eventType,
                    'tenant_id' => $tenantId
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to record security event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event_type' => $eventType            ]);
            throw $e;
        }
    }

    public function getEvents(int $tenantId, array $filters = []): array 
    {
        $query = SecurityEvent::where('tenant_id', $tenantId);

        // Apply filters
        if (isset($filters['type'])) {
            $query->where('event_type', $filters['type']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();    }

    public function checkAlertRules(int $tenantId, string $eventType): bool 
    {
        $rule = SecurityAlertRule::where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            return false;
        }

        // Count events in the time window
        $count = SecurityEvent::where('tenant_id', $tenantId)
            ->where('event_type', $eventType)
            ->where('event_timestamp', '>=', now()->subMinutes($rule->window_minutes))
            ->count();

        if ($count >= $rule->threshold) {
            $this->alertHandler->handleAlert($rule->id, [
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'event_count' => $count,
                'threshold' => $rule->threshold,
                'window_minutes' => $rule->window_minutes
            ]);
            return true;
        }

        return false;

        return $alertTriggered;
    }

    /**
     * Get the cache key for event counts
     */
    private function getEventCountKey(int $tenantId, string $eventType): string
    {
        return "security_events:{$tenantId}:{$eventType}:count";
    }
}