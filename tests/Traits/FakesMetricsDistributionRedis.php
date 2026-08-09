<?php

namespace Tests\Traits;

use App\Support\MetricStores\RedisMetricDistributionStore;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade with an in-memory store so
 * App\Support\MetricStores\RedisMetricDistributionStore (WU2, T053
 * foundation) can be exercised without a real Redis server (CI provisions
 * no Redis service) — mirrors tests/Traits/FakesCircuitBreakerRedis.php's
 * established pattern: a Mockery double registered against
 * Redis::shouldReceive('connection'), backed by plain in-memory arrays for
 * the sorted sets (percentile samples) and sets (cardinality tracking),
 * with eval() dispatched to a PHP re-implementation of each named Lua
 * script matched by exact script text.
 */
trait FakesMetricsDistributionRedis
{
    /** @var array<string, array<string, float>> ZSET key => [member => score] */
    protected array $fakeMetricsZsetStore = [];

    /** @var array<string, array<string, bool>> SET key => [member => true] */
    protected array $fakeMetricsSetStore = [];

    /** @var array<string, \Mockery\MockInterface> */
    protected array $fakeMetricsDistributionDoubles = [];

    protected function fakeMetricsDistributionRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();
        $this->fakeMetricsDistributionDoubles[$connection] = $double;

        $double->shouldReceive('zrange')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key, $start, $stop) {
                $members = $this->fakeMetricsZsetStore[$key] ?? [];
                asort($members); // sort by score ascending, mirroring ZRANGE's default order
                $ordered = array_keys($members);

                // Only 0,-1 (full range) is used by RedisMetricDistributionStore today.
                if ($start === 0 && $stop === -1) {
                    return array_values($ordered);
                }

                return array_values(array_slice($ordered, $start, $stop < 0 ? null : ($stop - $start + 1)));
            });

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                $key = $rest[0];
                $argv = array_slice($rest, $numKeys);

                if ($script === RedisMetricDistributionStore::SAMPLE_TRIM_SCRIPT) {
                    return $this->fakeEvalSampleTrim($key, $argv);
                }

                if ($script === RedisMetricDistributionStore::CARDINALITY_ADMIT_SCRIPT) {
                    return $this->fakeEvalCardinalityAdmit($key, $argv);
                }

                throw new \RuntimeException('FakesMetricsDistributionRedis: unrecognized eval() script; add a fake for it.');
            });

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * PHP re-implementation of RedisMetricDistributionStore::SAMPLE_TRIM_SCRIPT.
     *
     * ARGV: [score, member, cap, ttl]
     */
    protected function fakeEvalSampleTrim(string $key, array $argv): int
    {
        [$score, $member, $cap, $ttl] = $argv;
        $cap = (int) $cap;
        unset($ttl); // fake store has no real TTL/EXPIRE semantics to apply

        $zset = &$this->fakeMetricsZsetStore[$key];
        $zset ??= [];
        $zset[$member] = (float) $score;

        if (count($zset) > $cap) {
            // Evict the lowest-scored (oldest) members first, mirroring
            // ZREMRANGEBYRANK(key, 0, count-cap-1) on a set ordered by score.
            asort($zset);
            $excess = count($zset) - $cap;
            $zset = array_slice($zset, $excess, null, true);
        }

        return count($zset);
    }

    /**
     * PHP re-implementation of RedisMetricDistributionStore::CARDINALITY_ADMIT_SCRIPT.
     *
     * ARGV: [combo, budget, ttl]
     */
    protected function fakeEvalCardinalityAdmit(string $key, array $argv): int
    {
        [$combo, $budget, $ttl] = $argv;
        $budget = (int) $budget;
        unset($ttl); // fake store has no real TTL/EXPIRE semantics to apply

        $set = &$this->fakeMetricsSetStore[$key];
        $set ??= [];

        if (isset($set[$combo])) {
            return 1;
        }

        if (count($set) >= $budget) {
            return 0;
        }

        $set[$combo] = true;

        return 1;
    }

    /** Peek at a per-combination sample ZSET's size, for cap-enforcement assertions. */
    protected function fakeMetricsZsetSize(string $key): int
    {
        return count($this->fakeMetricsZsetStore[$key] ?? []);
    }

    /** Peek at a per-metric-name combination-tracking SET's size, for budget assertions. */
    protected function fakeMetricsSetSize(string $key): int
    {
        return count($this->fakeMetricsSetStore[$key] ?? []);
    }

    protected function resetFakeMetricsDistributionStore(): void
    {
        $this->fakeMetricsZsetStore = [];
        $this->fakeMetricsSetStore = [];
    }
}
