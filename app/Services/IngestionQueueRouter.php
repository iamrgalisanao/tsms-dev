<?php

namespace App\Services;

class IngestionQueueRouter
{
    public function intakeQueueForTenant(int|string|null $tenantId): string
    {
        $tenantId = (int) $tenantId;

        if ($this->isPilotTenant($tenantId)) {
            return 'transaction-intake:s-'.config('tsms.intake.vip_shard', 'vip');
        }

        return 'transaction-intake:s'.$this->shardIndex($tenantId, $this->intakeShardCount());
    }

    public function processingQueueForTenant(int|string|null $tenantId): string
    {
        $tenantId = (int) $tenantId;

        return 'transaction-processing:s'.$this->shardIndex($tenantId, $this->processingShardCount());
    }

    /**
     * Bare intake shard identifier for a tenant, for log-context use
     * (e.g. a `shard` field) where callers need the shard value on its own
     * rather than embedded in a full queue name string. Mirrors
     * intakeQueueForTenant()'s pilot-tenant routing so the reported shard
     * matches the queue the tenant is actually routed to.
     */
    public function intakeShardIndexForTenant(int|string|null $tenantId): int|string
    {
        $tenantId = (int) $tenantId;

        if ($this->isPilotTenant($tenantId)) {
            return config('tsms.intake.vip_shard', 'vip');
        }

        return $this->shardIndex($tenantId, $this->intakeShardCount());
    }

    /**
     * Bare processing shard index for a tenant, for log-context use.
     * Mirrors processingQueueForTenant() without the queue-name prefix.
     */
    public function processingShardIndexForTenant(int|string|null $tenantId): int
    {
        $tenantId = (int) $tenantId;

        return $this->shardIndex($tenantId, $this->processingShardCount());
    }

    private function isPilotTenant(int $tenantId): bool
    {
        return in_array((string) $tenantId, array_map('strval', config('tsms.rollout.pilot_tenants', [])), true);
    }

    private function intakeShardCount(): int
    {
        return max(1, (int) config('tsms.intake.shard_count', 8));
    }

    private function processingShardCount(): int
    {
        return max(1, (int) config('tsms.processing.shard_count', $this->intakeShardCount()));
    }

    private function shardIndex(int $tenantId, int $shardCount): int
    {
        return crc32((string) $tenantId) % $shardCount;
    }
}
