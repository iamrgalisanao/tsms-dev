<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * WU5: Redis-backed, TTL-bounded tenant fairness override store — a
 * temporary, incident-response control for throttling ONE specific tenant
 * during a live drill/incident, without redeploying config for all tenants.
 *
 * This is explicitly NOT the tenant-tier override system that plan.md's
 * "Fairness Architecture" subsection (point 7) already deferred/out of
 * scope for the broader feature (a persistent, config-driven tier/policy
 * system with no expiry). WU5 does not reopen that decision: this store
 * has no tier concept, no persistent policy schema, applies to exactly one
 * tenant at a time, and every override expires by design — a genuinely
 * different use case (temporary incident response) that coexists with
 * point 7's deferred decision without conflict.
 *
 * Modes:
 *  - inherit: use the normal global/tenant fairness limit (the default
 *    when no override exists, or when one has expired/is unreadable).
 *  - reduced_limit: apply a lower tenant-specific limit for the fixed-
 *    window check that follows (IngestionFairnessService::checkTenant()).
 *  - blocked: reject ingestion for this tenant immediately, before the
 *    base fixed-window checks even run.
 *
 * TTL is mandatory on every override — there is no permanent override by
 * default — and is hard-capped by config('tsms.tenant_throttle.max_ttl_seconds')
 * (Architecture Invariant 7: this config value is defined in THIS commit,
 * the one that enforces it, not introduced later by WU8). A requested TTL
 * exceeding the cap is rejected outright, not silently clamped: an
 * operator issuing an incident-response override needs to know the REAL
 * expiry they are getting, not discover later that a silently-shortened
 * TTL let a blocked tenant back in earlier than assumed.
 *
 * Atomic set/clear via single-key (KEYS[1]) Lua scripts (Architecture
 * Invariant 2), matching CircuitBreaker's/SkewRankingService's existing
 * EVAL convention. Reads (resolve()) are plain GET+TTL calls — not
 * scripted — because a read has no multi-step race to protect against;
 * only the set/clear mutations need atomicity.
 *
 * Fail-open primacy (Architecture Invariant 1) is absolute here: ANY Redis
 * error, a missing key, an expired key, or a malformed/undecodable stored
 * value all resolve to MODE_INHERIT with every other field null — NEVER
 * MODE_BLOCKED, regardless of what was last written. A Redis outage must
 * never accidentally throttle/block a tenant that has an active override;
 * it must fail toward "normal fairness behavior applies", matching
 * IngestionFairnessService's own fail-open convention for its base check.
 *
 * Honest expiry/absence semantics (plan.md's WU5 section): Redis TTL
 * expiry does not execute application code, and a missing key alone cannot
 * distinguish never-created, explicitly-cleared, or naturally-expired.
 * This class does not claim to. It logs create/replace/clear immediately,
 * as real observed events, and reports a missing key as 'absent' (a
 * diagnostic-only signal, exposed via resolve()'s 'source' field) — never
 * as "was explicitly cleared" or "TTL-expired". No scheduled sweeper or
 * Redis keyspace notifications are used (deliberate scope control, per
 * plan.md).
 */
class TenantFairnessOverrideService
{
    public const MODE_INHERIT = 'inherit';

    public const MODE_REDUCED_LIMIT = 'reduced_limit';

    public const MODE_BLOCKED = 'blocked';

    private const VALID_MODES = [self::MODE_INHERIT, self::MODE_REDUCED_LIMIT, self::MODE_BLOCKED];

    /**
     * Atomic single-key set (Architecture Invariant 2): SET the override's
     * JSON payload with an EX ttl, returning whether a prior override
     * already existed at this key (1) or not (0) — purely so set() can log
     * 'created' vs 'replaced' as a real, immediately-observed event, never
     * as a claim about anything that happened while this process wasn't
     * looking.
     *
     * KEYS[1] = the tenant's override key.
     * ARGV[1] = JSON-encoded override payload.
     * ARGV[2] = ttl_seconds.
     */
    public const SET_SCRIPT = <<<'LUA'
local key = KEYS[1]
local value = ARGV[1]
local ttlSeconds = tonumber(ARGV[2])

local existed = redis.call('EXISTS', key)
redis.call('SET', key, value, 'EX', ttlSeconds)

return existed
LUA;

    /**
     * Atomic single-key clear (Architecture Invariant 2): DEL the key,
     * returning whether it existed beforehand (1) or not (0) so clear()
     * can report idempotently — clearing an already-absent override is a
     * no-op success, never an error.
     *
     * KEYS[1] = the tenant's override key.
     */
    public const CLEAR_SCRIPT = <<<'LUA'
local key = KEYS[1]
local existed = redis.call('EXISTS', key)
redis.call('DEL', key)

return existed
LUA;

    /**
     * Sets (creates or replaces) a tenant's override.
     *
     * Validates, in order: mode is one of inherit/reduced_limit/blocked; a
     * positive --limit is supplied when mode is reduced_limit; ttl is
     * positive and does not exceed the configured maximum; reason and
     * operator are non-empty (audit trail); the tenant ID corresponds to a
     * known, non-deleted tenant.
     *
     * @return array{tenant_id:int, mode:string, limit:?int, reason:string, operator:string, set_at:string, expires_at:string, ttl_seconds:int, replaced:bool}
     *
     * @throws InvalidArgumentException on any validation failure.
     */
    public function set(int $tenantId, string $mode, ?int $limit, int $ttlSeconds, string $reason, string $operator): array
    {
        if (! in_array($mode, self::VALID_MODES, true)) {
            throw new InvalidArgumentException("Invalid override mode '{$mode}'; must be one of: ".implode(', ', self::VALID_MODES));
        }

        if ($mode === self::MODE_REDUCED_LIMIT && (! is_int($limit) || $limit <= 0)) {
            throw new InvalidArgumentException('reduced_limit mode requires a positive --limit');
        }

        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('--ttl must be a positive number of seconds');
        }

        $maxTtl = $this->maxTtlSeconds();

        if ($ttlSeconds > $maxTtl) {
            throw new InvalidArgumentException("--ttl ({$ttlSeconds}s) exceeds the configured maximum override TTL ({$maxTtl}s); reissue with a smaller value");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('--reason is required and cannot be empty');
        }

        if (trim($operator) === '') {
            throw new InvalidArgumentException('operator identity is required and cannot be empty');
        }

        if (! Tenant::whereKey($tenantId)->exists()) {
            throw new InvalidArgumentException("Tenant {$tenantId} does not exist; refusing to set an override for an unknown tenant");
        }

        $effectiveLimit = $mode === self::MODE_REDUCED_LIMIT ? $limit : null;
        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttlSeconds);

        $payload = [
            'mode' => $mode,
            'limit' => $effectiveLimit,
            'reason' => $reason,
            'operator' => $operator,
            'set_at' => $now->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ];

        try {
            $connection = Redis::connection($this->redisConnection());

            $existed = (bool) $connection->eval(
                self::SET_SCRIPT,
                1,
                $this->key($tenantId),
                json_encode($payload),
                $ttlSeconds
            );
        } catch (\Throwable $e) {
            Log::error('TenantFairnessOverrideService: failed to set override', [
                'tenant_id' => $tenantId,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to set override for tenant {$tenantId}: Redis error ({$e->getMessage()})", previous: $e);
        }

        // Real, immediately-observed event — never a claim about anything
        // that happened while this process wasn't looking ("honest model",
        // plan.md's WU5 section).
        Log::info($existed ? 'TenantFairnessOverrideService: override replaced' : 'TenantFairnessOverrideService: override created', [
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'limit' => $effectiveLimit,
            'ttl_seconds' => $ttlSeconds,
            'expires_at' => $payload['expires_at'],
            'reason' => $reason,
            'operator' => $operator,
        ]);

        return [
            'tenant_id' => $tenantId,
            'mode' => $mode,
            'limit' => $effectiveLimit,
            'reason' => $reason,
            'operator' => $operator,
            'set_at' => $payload['set_at'],
            'expires_at' => $payload['expires_at'],
            'ttl_seconds' => $ttlSeconds,
            'replaced' => $existed,
        ];
    }

    /**
     * Idempotent clear: clearing a tenant with no existing override is a
     * no-op success, never an error.
     *
     * @return array{tenant_id:int, operator:string, existed:bool}
     *
     * @throws InvalidArgumentException when operator identity is empty.
     */
    public function clear(int $tenantId, string $operator): array
    {
        if (trim($operator) === '') {
            throw new InvalidArgumentException('operator identity is required and cannot be empty');
        }

        try {
            $connection = Redis::connection($this->redisConnection());

            $existed = (bool) $connection->eval(
                self::CLEAR_SCRIPT,
                1,
                $this->key($tenantId)
            );
        } catch (\Throwable $e) {
            Log::error('TenantFairnessOverrideService: failed to clear override', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to clear override for tenant {$tenantId}: Redis error ({$e->getMessage()})", previous: $e);
        }

        // Real, immediately-observed event, logged every time regardless of
        // whether anything was actually present to clear.
        Log::info('TenantFairnessOverrideService: clear requested', [
            'tenant_id' => $tenantId,
            'operator' => $operator,
            'existed' => $existed,
        ]);

        return [
            'tenant_id' => $tenantId,
            'operator' => $operator,
            'existed' => $existed,
        ];
    }

    /**
     * Resolves a tenant's current override state. This is the method
     * IngestionFairnessService::checkTenantOverride() calls on every
     * ingestion request, so its fail-open contract (Architecture
     * Invariant 1) is absolute: ANY Redis error, a missing key, an expired
     * key (defensively re-checked via TTL even though EXPIRE should have
     * already removed it), or a malformed/undecodable stored value all
     * resolve to MODE_INHERIT with every other field null — never
     * MODE_BLOCKED, regardless of what was last written.
     *
     * 'source' is diagnostic only and is NEVER consumed by fairness
     * admission logic: 'override' when a real, live override was read;
     * 'absent' when the key is genuinely not present right now (this does
     * NOT mean "never created", "explicitly cleared", or "TTL-expired" —
     * the store cannot honestly tell those apart from key absence alone);
     * 'redis_error' when the read itself failed or the stored payload was
     * unreadable. `ingestion:tenant-throttle:status` surfaces 'source' so
     * an operator checking status during a Redis outage sees a real
     * diagnostic signal instead of a silently-identical "inherit".
     *
     * @return array{mode:string, limit:?int, reason:?string, operator:?string, set_at:?string, expires_at:?string, retry_after_seconds:?int, source:string}
     */
    public function resolve(int $tenantId): array
    {
        try {
            $connection = Redis::connection($this->redisConnection());
            $key = $this->key($tenantId);

            $raw = $connection->get($key);

            if ($raw === null || $raw === false) {
                return $this->inherit('absent');
            }

            $ttl = (int) $connection->ttl($key);

            if ($ttl <= 0) {
                // Missing/expired race between GET and TTL (or a key that
                // somehow lost its TTL) — treated as absent, never as an
                // active override (fail-open primacy).
                return $this->inherit('absent');
            }

            $data = json_decode((string) $raw, true);

            if (! is_array($data) || ! isset($data['mode']) || ! in_array($data['mode'], self::VALID_MODES, true)) {
                Log::error('TenantFairnessOverrideService: unreadable override payload, failing open to inherit', [
                    'tenant_id' => $tenantId,
                ]);

                return $this->inherit('redis_error');
            }

            return [
                'mode' => $data['mode'],
                'limit' => isset($data['limit']) ? (int) $data['limit'] : null,
                'reason' => $data['reason'] ?? null,
                'operator' => $data['operator'] ?? null,
                'set_at' => $data['set_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'retry_after_seconds' => max(1, $ttl),
                'source' => 'override',
            ];
        } catch (\Throwable $e) {
            // Fail open (Architecture Invariant 1): this store's own
            // failure must never resolve to 'blocked' — only ever
            // 'inherit', so a Redis outage can, at worst, let an
            // incident-response override lapse early, never accidentally
            // throttle/block a tenant that has no organic fairness issue.
            Log::error('TenantFairnessOverrideService: failed to resolve override, failing open to inherit', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->inherit('redis_error');
        }
    }

    private function inherit(string $source): array
    {
        return [
            'mode' => self::MODE_INHERIT,
            'limit' => null,
            'reason' => null,
            'operator' => null,
            'set_at' => null,
            'expires_at' => null,
            'retry_after_seconds' => null,
            'source' => $source,
        ];
    }

    private function key(int $tenantId): string
    {
        return $this->keyPrefix().$tenantId;
    }

    private function redisConnection(): string
    {
        return (string) config('tsms.tenant_throttle.redis_connection', 'default');
    }

    private function keyPrefix(): string
    {
        return (string) config('tsms.tenant_throttle.key_prefix', 'fairness:override:');
    }

    private function maxTtlSeconds(): int
    {
        return max(1, (int) config('tsms.tenant_throttle.max_ttl_seconds', 14400));
    }
}
