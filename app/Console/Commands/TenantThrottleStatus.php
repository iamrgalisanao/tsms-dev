<?php

namespace App\Console\Commands;

use App\Services\TenantFairnessOverrideService;
use Illuminate\Console\Command;

/**
 * WU5: read-only status check for a tenant's current fairness override.
 * Never mutates state (Architecture Invariant 6's read-only spirit applies
 * here too, even though this is a console command rather than a WU7
 * observability endpoint).
 *
 * 'source' surfaces TenantFairnessOverrideService::resolve()'s honest
 * expiry/absence semantics: a diagnostic-only signal distinguishing "the
 * override key genuinely isn't present right now" (absent) from "the read
 * itself failed" (redis_error) — never a claim about WHY a key is absent
 * (never-created vs. explicitly-cleared vs. TTL-expired are indistinguishable
 * from key absence alone, per plan.md's WU5 section).
 */
class TenantThrottleStatus extends Command
{
    protected $signature = 'ingestion:tenant-throttle:status
        {--tenant= : Tenant ID to inspect}';

    protected $description = "Show a tenant's current fairness override status (inherit/reduced_limit/blocked)";

    public function handle(TenantFairnessOverrideService $overrideService): int
    {
        $tenant = $this->option('tenant');

        if (! is_numeric($tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be a positive integer');

            return self::FAILURE;
        }

        $tenantId = (int) $tenant;
        $status = $overrideService->resolve($tenantId);

        if ($status['source'] === 'redis_error') {
            $this->warn("Could not read override state for tenant {$tenantId}: the override read itself failed. Fairness will FAIL OPEN to 'inherit' for this tenant until Redis is reachable again (Architecture Invariant 1) — this does NOT mean no override was ever set.");
        } elseif ($status['source'] === 'absent') {
            $this->line("No active override for tenant {$tenantId} (mode: inherit). This does not distinguish 'never set' from 'explicitly cleared' or 'TTL-expired' — the store cannot tell these apart from key absence alone.");
        }

        $this->table(
            ['Tenant', 'Mode', 'Limit', 'Retry-After (s)', 'Expires At', 'Operator', 'Reason', 'Source'],
            [[
                $tenantId,
                $status['mode'],
                $status['limit'] ?? '-',
                $status['retry_after_seconds'] ?? '-',
                $status['expires_at'] ?? '-',
                $status['operator'] ?? '-',
                $status['reason'] ?? '-',
                $status['source'],
            ]]
        );

        return self::SUCCESS;
    }
}
