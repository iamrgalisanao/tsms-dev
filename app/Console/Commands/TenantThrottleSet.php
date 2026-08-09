<?php

namespace App\Console\Commands;

use App\Services\TenantFairnessOverrideService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * WU5: sets (creates or replaces) a TTL-bounded tenant fairness override —
 * a temporary, incident-response control for throttling one specific
 * tenant during a live drill/incident, distinct from the deferred,
 * persistent tenant-tier policy system (plan.md's "Fairness Architecture"
 * subsection, point 7; see TenantFairnessOverrideService's class doc-comment
 * for the full reconciliation).
 *
 * --operator is a required option here even though plan.md's abbreviated
 * WU5 command signature does not list it: the spec's own requirement that
 * "the authorizing operator/CLI identity is recorded on every command
 * invocation for the audit trail" needs a real, intentional identity
 * string. Inferring one from the OS process owner (e.g. a shared
 * `deploy`/`www-data` account under which Artisan commands typically run)
 * would provide no genuine audit value, so this command requires the
 * caller to supply it explicitly instead.
 */
class TenantThrottleSet extends Command
{
    protected $signature = 'ingestion:tenant-throttle:set
        {--tenant= : Tenant ID to throttle}
        {--mode= : Override mode: inherit|reduced_limit|blocked}
        {--limit= : Tenant-specific request limit (required when --mode=reduced_limit)}
        {--ttl= : Override TTL in seconds (must not exceed the configured maximum)}
        {--reason= : Reason for this override, recorded for the audit trail}
        {--operator= : Authorizing operator/CLI identity, recorded for the audit trail}';

    protected $description = 'Set (create or replace) a TTL-bounded tenant fairness override for incident response';

    public function handle(TenantFairnessOverrideService $overrideService): int
    {
        $tenant = $this->option('tenant');
        $mode = $this->option('mode');
        $limit = $this->option('limit');
        $ttl = $this->option('ttl');
        $reason = (string) $this->option('reason');
        $operator = (string) $this->option('operator');

        if (! is_numeric($tenant) || (int) $tenant <= 0) {
            $this->error('--tenant must be a positive integer');

            return self::FAILURE;
        }

        if (! is_numeric($ttl) || (int) $ttl <= 0) {
            $this->error('--ttl must be a positive integer (seconds)');

            return self::FAILURE;
        }

        if ($operator === '') {
            $this->error('--operator is required (authorizing operator/CLI identity for the audit trail)');

            return self::FAILURE;
        }

        $limitValue = null;

        if ($limit !== null && $limit !== '') {
            if (! is_numeric($limit) || (int) $limit <= 0) {
                $this->error('--limit must be a positive integer when supplied');

                return self::FAILURE;
            }

            $limitValue = (int) $limit;
        }

        try {
            $result = $overrideService->set(
                (int) $tenant,
                (string) $mode,
                $limitValue,
                (int) $ttl,
                $reason,
                $operator
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Failed to set override: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info($result['replaced'] ? 'Override replaced.' : 'Override created.');
        $this->table(
            ['Tenant', 'Mode', 'Limit', 'TTL (s)', 'Expires At', 'Operator', 'Reason'],
            [[
                $result['tenant_id'],
                $result['mode'],
                $result['limit'] ?? '-',
                $result['ttl_seconds'],
                $result['expires_at'],
                $result['operator'],
                $result['reason'],
            ]]
        );

        return self::SUCCESS;
    }
}
