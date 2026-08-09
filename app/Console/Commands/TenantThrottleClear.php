<?php

namespace App\Console\Commands;

use App\Services\TenantFairnessOverrideService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * WU5: clears a tenant's fairness override. Idempotent — clearing a
 * tenant with no existing override is a no-op success, never an error
 * (plan.md's WU5 section, explicit decision).
 *
 * --operator is required for the same audit-trail reason documented on
 * TenantThrottleSet.
 */
class TenantThrottleClear extends Command
{
    protected $signature = 'ingestion:tenant-throttle:clear
        {--tenant= : Tenant ID to clear the override for}
        {--operator= : Authorizing operator/CLI identity, recorded for the audit trail}';

    protected $description = 'Clear a tenant fairness override (idempotent — a no-op success if none exists)';

    public function handle(TenantFairnessOverrideService $overrideService): int
    {
        $tenant = $this->option('tenant');
        $operator = (string) $this->option('operator');

        if (! is_numeric($tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be a positive integer');

            return self::FAILURE;
        }

        if ($operator === '') {
            $this->error('--operator is required (authorizing operator/CLI identity for the audit trail)');

            return self::FAILURE;
        }

        try {
            $result = $overrideService->clear((int) $tenant, $operator);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Failed to clear override: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info($result['existed']
            ? "Override cleared for tenant {$result['tenant_id']}."
            : "No override existed for tenant {$result['tenant_id']} (no-op success).");

        return self::SUCCESS;
    }
}
