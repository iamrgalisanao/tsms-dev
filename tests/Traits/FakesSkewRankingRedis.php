<?php

namespace Tests\Traits;

use App\Services\SkewRankingService;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade with an in-memory ZSET store so
 * App\Services\SkewRankingService (WU4, T053 remainder) can be exercised
 * without a real Redis server (CI provisions no Redis service) — mirrors
 * tests/Traits/FakesMetricsDistributionRedis.php's established pattern: a
 * Mockery double registered against Redis::shouldReceive('connection'),
 * with eval() dispatched to a PHP re-implementation of each named Lua
 * script matched by exact script text.
 */
trait FakesSkewRankingRedis
{
    /** @var array<string, array<string, float>> ZSET key => [member => score] */
    protected array $fakeSkewZsetStore = [];

    protected function fakeSkewRankingRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                $key = $rest[0];
                $argv = array_slice($rest, $numKeys);

                if ($script === SkewRankingService::RANK_INCREMENT_SCRIPT) {
                    return $this->fakeEvalSkewRankIncrement($key, $argv);
                }

                if ($script === SkewRankingService::TOP_N_SCRIPT) {
                    return $this->fakeEvalSkewTopN($key, $argv);
                }

                throw new \RuntimeException('FakesSkewRankingRedis: unrecognized eval() script; add a fake for it.');
            });

        $double->shouldReceive('zcard')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key) => count($this->fakeSkewZsetStore[$key] ?? []));

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * PHP re-implementation of SkewRankingService::RANK_INCREMENT_SCRIPT.
     *
     * ARGV: [member, increment, cap, ttl]
     */
    protected function fakeEvalSkewRankIncrement(string $key, array $argv): int
    {
        [$member, $increment, $cap] = [$argv[0], (float) $argv[1], (int) $argv[2]];

        $zset = $this->fakeSkewZsetStore[$key] ?? [];
        $zset[$member] = ($zset[$member] ?? 0.0) + $increment;

        if (count($zset) > $cap) {
            // Evict the lowest-scored member(s) first, matching
            // ZREMRANGEBYRANK(0, count - cap - 1)'s ascending-rank eviction.
            asort($zset);
            $zset = array_slice($zset, count($zset) - $cap, null, true);
        }

        $this->fakeSkewZsetStore[$key] = $zset;

        return count($zset);
    }

    /**
     * PHP re-implementation of SkewRankingService::TOP_N_SCRIPT.
     *
     * ARGV: [limit]
     *
     * @return list<string> flat [member1, score1, member2, score2, ...] in descending-score order
     */
    protected function fakeEvalSkewTopN(string $key, array $argv): array
    {
        $limit = (int) $argv[0];

        $zset = $this->fakeSkewZsetStore[$key] ?? [];
        arsort($zset); // descending by score

        $flat = [];
        $i = 0;
        foreach ($zset as $member => $score) {
            if ($i++ >= $limit) {
                break;
            }
            $flat[] = (string) $member;
            $flat[] = (string) $score;
        }

        return $flat;
    }

    protected function fakeSkewZsetSize(string $key): int
    {
        return count($this->fakeSkewZsetStore[$key] ?? []);
    }

    protected function resetFakeSkewRankingRedisStore(): void
    {
        $this->fakeSkewZsetStore = [];
    }
}
