<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Bounded, time-windowed "top-N talkers" ranking for tenant/terminal
 * request volume (WU4, T053 remainder).
 *
 * Architecture Invariant 5 (bounded cardinality) applies directly: this is
 * the one genuinely new piece of Redis infrastructure in this work unit, so
 * it follows App\Support\MetricStores\RedisMetricDistributionStore's
 * established shape exactly — one bounded Redis sorted set per dimension
 * per time window, a single-key (KEYS[1]) Lua script for the
 * atomic increment+cap+refresh-TTL operation (Invariant 2, matching
 * CircuitBreaker's and RedisMetricDistributionStore's existing scripts),
 * explicit member cap enforced on every insert (evicting the lowest-ranked
 * member via ZREMRANGEBYRANK once the cap is exceeded), and an explicit TTL
 * on the whole key so a finished window expires on its own.
 *
 * This is deliberately a different structure from the unbounded per-tenant
 * `tenant.{id}.intake_count` Cache counter TransactionIntakeService already
 * writes (a WU2 finding): that counter answers "what is tenant X's count"
 * for one known tenant and grows one new Cache key per distinct tenant ID
 * seen, forever — fine for a handful of known tenants, but not safe to
 * enumerate/rank at scale. This structure answers "who are the top N
 * tenants/terminals in the current window" and never holds more than
 * member_cap members at a time, regardless of how many distinct
 * tenants/terminals are ever seen.
 *
 * Fails silently (never throws) on any Redis error — this is an
 * observability-only structure and must never affect ingestion admission
 * or behavior, mirroring App\Support\Metrics's own fail-safe contract.
 */
class SkewRankingService
{
    private const DIMENSION_TENANT = 'tenant';

    private const DIMENSION_TERMINAL = 'terminal';

    /**
     * Atomically increments a member's score in the current window's
     * ranking set and, if the member count now exceeds the configured cap,
     * evicts the lowest-ranked (least active) member(s) via
     * ZREMRANGEBYRANK — ZSET rank 0 is always the lowest score, so this
     * never evicts a top talker to make room for a newcomer. Refreshes the
     * key's TTL on every call so an actively-updated window's key survives
     * for its full configured lifetime.
     *
     * KEYS[1] = the window's ranking ZSET key.
     * ARGV[1] = member (tenant or terminal ID, as a string).
     * ARGV[2] = increment amount.
     * ARGV[3] = member_cap (max distinct members retained).
     * ARGV[4] = ttl_seconds.
     *
     * Returns the ZSET's cardinality after the increment+trim, for optional
     * diagnostic use by the caller.
     */
    public const RANK_INCREMENT_SCRIPT = <<<'LUA'
local key = KEYS[1]
local member = ARGV[1]
local increment = tonumber(ARGV[2])
local cap = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])

redis.call('ZINCRBY', key, increment, member)

local count = redis.call('ZCARD', key)
if count > cap then
    redis.call('ZREMRANGEBYRANK', key, 0, count - cap - 1)
end

redis.call('EXPIRE', key, ttl)

return count
LUA;

    /**
     * Bounded top-N read. Uses Lua (rather than a bare
     * `$connection->zrevrange($key, 0, $n - 1, ['withscores' => true])`
     * call) so the WITHSCORES semantics are resolved entirely inside Redis
     * and the result shape (a flat member/score array) is identical
     * regardless of which PHP Redis client (phpredis vs predis) this
     * connection uses — the same portability reasoning already applied to
     * every other atomic/structured operation in this codebase's Redis
     * services.
     *
     * KEYS[1] = the window's ranking ZSET key.
     * ARGV[1] = limit (already clamped to max_top_n by the caller).
     *
     * Returns a flat array [member1, score1, member2, score2, ...] in
     * descending-score order, exactly ZREVRANGE ... WITHSCORES's own
     * return shape.
     */
    public const TOP_N_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])

return redis.call('ZREVRANGE', key, 0, limit - 1, 'WITHSCORES')
LUA;

    public function recordTenant(int|string $tenantId, int $by = 1): void
    {
        $this->record(self::DIMENSION_TENANT, (string) $tenantId, $by);
    }

    public function recordTerminal(int|string $terminalId, int $by = 1): void
    {
        $this->record(self::DIMENSION_TERMINAL, (string) $terminalId, $by);
    }

    /**
     * Top N tenants in the current window, most active first.
     *
     * @return list<array{member: string, count: float}>
     */
    public function topTenants(int $limit = 10): array
    {
        return $this->top(self::DIMENSION_TENANT, $limit);
    }

    /** @return list<array{member: string, count: float}> */
    public function topTerminals(int $limit = 10): array
    {
        return $this->top(self::DIMENSION_TERMINAL, $limit);
    }

    /** Current window's tracked-member count for the tenant ranking ZSET (test/diagnostic use). */
    public function tenantMemberCount(): int
    {
        return $this->memberCount(self::DIMENSION_TENANT);
    }

    /** Current window's tracked-member count for the terminal ranking ZSET (test/diagnostic use). */
    public function terminalMemberCount(): int
    {
        return $this->memberCount(self::DIMENSION_TERMINAL);
    }

    private function record(string $dimension, string $member, int $by): void
    {
        try {
            $connection = Redis::connection($this->redisConnection());

            $connection->eval(
                self::RANK_INCREMENT_SCRIPT,
                1,
                $this->key($dimension),
                $member,
                $by,
                $this->memberCap(),
                $this->ttlSeconds()
            );
        } catch (\Throwable $e) {
            // Swallow — observability-only structure, must never affect
            // ingestion admission/behavior.
            Log::debug('SkewRankingService: failed to record sample, dropping', [
                'dimension' => $dimension,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<array{member: string, count: float}> */
    private function top(string $dimension, int $limit): array
    {
        // Bounded top-N reads only (Architecture Invariant 5): never let a
        // caller request more than max_top_n, regardless of what it asks
        // for.
        $limit = max(1, min($this->maxTopN(), $limit));

        try {
            $connection = Redis::connection($this->redisConnection());

            $flat = $connection->eval(
                self::TOP_N_SCRIPT,
                1,
                $this->key($dimension),
                $limit
            ) ?? [];

            $result = [];
            for ($i = 0; $i < count($flat); $i += 2) {
                $result[] = [
                    'member' => (string) $flat[$i],
                    'count' => (float) $flat[$i + 1],
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::debug('SkewRankingService: failed to read top-N ranking', [
                'dimension' => $dimension,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function memberCount(string $dimension): int
    {
        try {
            return (int) Redis::connection($this->redisConnection())->zcard($this->key($dimension));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Fixed-window bucketing, matching IngestionFairnessService's own
     * `intdiv(now, window_seconds)` convention exactly, using Carbon's
     * now() so the bucket honors Carbon::setTestNow() the same way the
     * rest of this codebase's time-sensitive services do.
     */
    private function key(string $dimension): string
    {
        $windowBucket = intdiv(now()->timestamp, $this->windowSeconds());

        return $this->keyPrefix()."{$dimension}:{$windowBucket}";
    }

    private function redisConnection(): string
    {
        return (string) config('tsms.metrics.skew.redis_connection', 'default');
    }

    private function keyPrefix(): string
    {
        return (string) config('tsms.metrics.skew.key_prefix', 'metrics:skew:');
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('tsms.metrics.skew.window_seconds', 300));
    }

    private function memberCap(): int
    {
        return max(1, (int) config('tsms.metrics.skew.member_cap', 500));
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('tsms.metrics.skew.ttl_seconds', 600));
    }

    private function maxTopN(): int
    {
        return max(1, (int) config('tsms.metrics.skew.max_top_n', 100));
    }
}
