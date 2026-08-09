<?php

namespace App\Support\MetricStores;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis-backed percentile distribution store (WU2, T053 foundation).
 *
 * Design (see specs/001-100-tenant-resilience/plan.md, WU2 and Architecture
 * Invariant 5 "bounded cardinality"):
 *
 * - One bounded Redis sorted set (ZSET) per metric-name+dimensions
 *   combination. Score = insertion timestamp (microtime), so the set is
 *   naturally ordered oldest-to-newest; member = "{value}|{random suffix}"
 *   so repeated identical values are never collapsed into one ZSET member
 *   (a plain ZADD would otherwise just update the existing member's score).
 * - Every insert runs SAMPLE_TRIM_SCRIPT — a single-key (KEYS[1]) Lua
 *   script (Architecture Invariant 2) that ZADDs, trims the set back down
 *   to sample_cap via ZREMRANGEBYRANK (evicting the oldest members first,
 *   since they sort lowest by score), and refreshes the key's TTL — all in
 *   one atomic round trip, mirroring App\Services\CircuitBreaker's existing
 *   single-key Lua convention.
 * - A second single-key Lua script, CARDINALITY_ADMIT_SCRIPT, guards a
 *   per-metric-name "known combinations" Redis SET against unbounded
 *   dimension-combination growth: an already-tracked combination is always
 *   admitted (and refreshes the tracking set's TTL); a new combination is
 *   admitted only while the set's cardinality is under cardinality_budget,
 *   otherwise the sample is dropped and a bounded, deduplicated warning is
 *   logged (never silently swallowed forever, never logged per-request).
 * - percentile() reads the full (bounded, <= sample_cap) member list back
 *   with ZRANGE, parses the numeric value out of each member, sorts
 *   ascending, and applies nearest-rank interpolation: for p in [0,100]
 *   and n samples, rank = ceil(p/100 * n), 1-indexed, clamped to [1, n].
 *   This is the simplest defensible interpolation for an approximate
 *   operational metric and avoids any averaging across ranks that could
 *   fabricate a value no sample actually had.
 */
class RedisMetricDistributionStore implements MetricDistributionStoreInterface
{
    /**
     * KEYS[1] = the per-combination sample ZSET key.
     * ARGV[1] = score (insertion order/time).
     * ARGV[2] = member ("{value}|{random suffix}").
     * ARGV[3] = sample_cap (max members retained).
     * ARGV[4] = sample_ttl_seconds.
     *
     * Returns the ZSET's cardinality after the insert+trim, for optional
     * diagnostic use by the caller.
     */
    public const SAMPLE_TRIM_SCRIPT = <<<'LUA'
local key = KEYS[1]
local score = ARGV[1]
local member = ARGV[2]
local cap = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])

redis.call('ZADD', key, score, member)

local count = redis.call('ZCARD', key)
if count > cap then
    redis.call('ZREMRANGEBYRANK', key, 0, count - cap - 1)
end

redis.call('EXPIRE', key, ttl)

return redis.call('ZCARD', key)
LUA;

    /**
     * KEYS[1] = the per-metric-name "known dimension combinations" SET key.
     * ARGV[1] = this sample's combination hash (see comboHash()).
     * ARGV[2] = cardinality_budget (max distinct combinations tracked).
     * ARGV[3] = cardinality_ttl_seconds.
     *
     * Returns 1 (admit) if the combination was already tracked (TTL
     * refreshed) or newly added under budget; 0 (reject) if it is a new
     * combination and the budget is already exhausted.
     */
    public const CARDINALITY_ADMIT_SCRIPT = <<<'LUA'
local key = KEYS[1]
local combo = ARGV[1]
local budget = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])

if redis.call('SISMEMBER', key, combo) == 1 then
    redis.call('EXPIRE', key, ttl)
    return 1
end

local count = redis.call('SCARD', key)
if count >= budget then
    return 0
end

redis.call('SADD', key, combo)
redis.call('EXPIRE', key, ttl)

return 1
LUA;

    protected string $redisConnection;

    protected string $keyPrefix;

    protected int $sampleCap;

    protected int $sampleTtlSeconds;

    protected int $cardinalityBudget;

    protected int $cardinalityTtlSeconds;

    /** @var list<string> */
    protected array $allowedDimensions;

    public function __construct()
    {
        $this->redisConnection = (string) config('tsms.metrics.distribution.redis_connection', 'default');
        $this->keyPrefix = (string) config('tsms.metrics.distribution.key_prefix', 'metrics:dist:');
        $this->sampleCap = max(1, (int) config('tsms.metrics.distribution.sample_cap', 1000));
        $this->sampleTtlSeconds = max(1, (int) config('tsms.metrics.distribution.sample_ttl_seconds', 3600));
        $this->cardinalityBudget = max(1, (int) config('tsms.metrics.distribution.cardinality_budget', 200));
        $this->cardinalityTtlSeconds = max(1, (int) config('tsms.metrics.distribution.cardinality_ttl_seconds', 3600));
        $this->allowedDimensions = array_values((array) config('tsms.metrics.distribution.allowed_dimensions', ['route', 'shard']));
    }

    public function sample(string $name, float $value, array $dims = []): void
    {
        try {
            $dims = $this->filterDimensions($dims);
            $combo = $this->comboHash($dims);
            $connection = Redis::connection($this->redisConnection);

            if (! $this->admitCombo($connection, $name, $combo)) {
                $this->logCardinalityRejection($name, $dims);

                return;
            }

            $member = sprintf('%s|%s', $value, Str::random(8));

            $connection->eval(
                self::SAMPLE_TRIM_SCRIPT,
                1,
                $this->sampleKey($name, $combo),
                microtime(true),
                $member,
                $this->sampleCap,
                $this->sampleTtlSeconds
            );
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }

    public function percentile(string $name, int $p, array $dims = []): array
    {
        $empty = ['value' => null, 'sample_count' => 0];

        try {
            $dims = $this->filterDimensions($dims);
            $combo = $this->comboHash($dims);
            $connection = Redis::connection($this->redisConnection);

            $members = $connection->zrange($this->sampleKey($name, $combo), 0, -1) ?: [];

            $values = [];
            foreach ($members as $member) {
                $separator = strrpos((string) $member, '|');
                $raw = $separator === false ? $member : substr((string) $member, 0, $separator);
                if (is_numeric($raw)) {
                    $values[] = (float) $raw;
                }
            }

            $count = count($values);
            if ($count === 0) {
                return $empty;
            }

            sort($values);

            $p = max(0, min(100, $p));
            $rank = (int) ceil(($p / 100) * $count);
            $index = max(0, min($count - 1, $rank - 1));

            return ['value' => $values[$index], 'sample_count' => $count];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /**
     * Keep only allowlisted dimension keys and sort by key so that
     * {route: a, shard: b} and {shard: b, route: a} canonicalize to the
     * same combination (and therefore the same Redis key/hash).
     *
     * @return array<string, scalar>
     */
    protected function filterDimensions(array $dims): array
    {
        $filtered = array_intersect_key($dims, array_flip($this->allowedDimensions));
        ksort($filtered);

        return $filtered;
    }

    /**
     * A short, deterministic token identifying this dimension combination.
     * Hashed (rather than used verbatim) so the resulting Redis key length
     * is bounded regardless of dimension value content.
     */
    protected function comboHash(array $dims): string
    {
        if ($dims === []) {
            return 'none';
        }

        $parts = [];
        foreach ($dims as $k => $v) {
            $parts[] = $k.'='.$v;
        }

        return substr(sha1(implode(',', $parts)), 0, 16);
    }

    protected function sampleKey(string $name, string $combo): string
    {
        return $this->keyPrefix.$name.':'.$combo;
    }

    protected function combosSetKey(string $name): string
    {
        return $this->keyPrefix.$name.':combos';
    }

    protected function admitCombo($connection, string $name, string $combo): bool
    {
        $result = $connection->eval(
            self::CARDINALITY_ADMIT_SCRIPT,
            1,
            $this->combosSetKey($name),
            $combo,
            $this->cardinalityBudget,
            $this->cardinalityTtlSeconds
        );

        return (int) $result === 1;
    }

    /**
     * Cardinality-budget rejections must be observable, not silently
     * swallowed forever — but also must not themselves become an unbounded
     * per-request log flood. Cache::add() (atomic "set if not present")
     * gives a simple per-metric-name dedupe window without any new Redis
     * structure of its own.
     */
    protected function logCardinalityRejection(string $name, array $dims): void
    {
        try {
            $dedupeKey = 'metrics:dist:cardinality-rejected-log:'.$name;
            if (Cache::add($dedupeKey, true, 300)) {
                Log::warning('Metrics: dimension-combination cardinality budget exceeded, sample dropped', [
                    'metric' => $name,
                    'dims' => $dims,
                    'cardinality_budget' => $this->cardinalityBudget,
                ]);
            }
        } catch (\Throwable $e) {
            // Swallow – metrics must never break business flow
        }
    }
}
