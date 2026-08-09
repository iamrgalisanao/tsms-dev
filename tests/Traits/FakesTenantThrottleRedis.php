<?php

namespace Tests\Traits;

use App\Services\TenantFairnessOverrideService;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade for App\Services\TenantFairnessOverrideService
 * (WU5), whose Redis footprint is: two single-key Lua scripts (SET_SCRIPT/
 * CLEAR_SCRIPT) for mutation, and plain GET+TTL for reads (resolve()).
 * Mirrors the established Mockery-double-over-Redis::connection() pattern
 * used by FakesIngestionFairnessRedis/FakesSkewRankingRedis, backed here by
 * a small in-memory value+expiry store so TTL expiry can be exercised with
 * Carbon::setTestNow() exactly like the rest of this codebase's
 * time-sensitive fakes.
 */
trait FakesTenantThrottleRedis
{
    /** @var array<string, array{value: string, expires_at: int}> */
    protected array $fakeThrottleStore = [];

    /**
     * Normal, fully-functional fake: eval() dispatches to SET_SCRIPT/
     * CLEAR_SCRIPT simulations, get()/ttl() read the same in-memory store.
     */
    protected function fakeTenantThrottleRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                return $this->dispatchFakeThrottleEval($script, $rest);
            });

        $double->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key) => $this->fakeThrottleGet($key));

        $double->shouldReceive('ttl')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key) => $this->fakeThrottleTtl($key));

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Simulates a total Redis outage: connection() resolution itself
     * throws, for both writes (set()/clear()) and reads (resolve()).
     */
    protected function fakeBrokenTenantThrottleRedis(string $connection = 'default'): void
    {
        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andThrow(new \RuntimeException('Redis connection refused (simulated outage)'));
    }

    /**
     * Writes (eval-backed set()/clear()) still work normally against the
     * shared in-memory store, but reads (get()/ttl()) always throw — this
     * is the shape needed to prove the fail-open requirement precisely:
     * "a Redis failure while READING an override must resolve to inherit,
     * even if a blocked override was genuinely set beforehand", as opposed
     * to the weaker (and less interesting) claim that a merely-absent
     * override resolves to inherit.
     */
    protected function fakeTenantThrottleRedisWithFailingReads(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                return $this->dispatchFakeThrottleEval($script, $rest);
            });

        $double->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andThrow(new \RuntimeException('simulated Redis GET failure'));

        $double->shouldReceive('ttl')
            ->zeroOrMoreTimes()
            ->andThrow(new \RuntimeException('simulated Redis TTL failure'));

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Simulates the atomic set()/clear() scripts themselves failing, while
     * leaving reads untouched (not needed by any current test, provided for
     * symmetry/future use the same way FakesIngestionFairnessRedis provides
     * fakeFairnessRedisWithFailingEval()).
     */
    protected function fakeTenantThrottleRedisWithFailingEval(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andThrow(new \RuntimeException('simulated Redis EVAL failure'));

        $double->shouldReceive('get')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key) => $this->fakeThrottleGet($key));

        $double->shouldReceive('ttl')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (string $key) => $this->fakeThrottleTtl($key));

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    private function dispatchFakeThrottleEval(string $script, array $rest): int
    {
        $key = $rest[0];

        if ($script === TenantFairnessOverrideService::SET_SCRIPT) {
            return $this->fakeEvalThrottleSet($key, (string) $rest[1], (int) $rest[2]);
        }

        if ($script === TenantFairnessOverrideService::CLEAR_SCRIPT) {
            return $this->fakeEvalThrottleClear($key);
        }

        throw new \RuntimeException('FakesTenantThrottleRedis: unrecognized eval() script; add a fake for it.');
    }

    /** PHP re-implementation of TenantFairnessOverrideService::SET_SCRIPT. */
    protected function fakeEvalThrottleSet(string $key, string $value, int $ttlSeconds): int
    {
        $existed = $this->fakeThrottleExists($key) ? 1 : 0;

        $this->fakeThrottleStore[$key] = [
            'value' => $value,
            'expires_at' => now()->timestamp + $ttlSeconds,
        ];

        return $existed;
    }

    /** PHP re-implementation of TenantFairnessOverrideService::CLEAR_SCRIPT. */
    protected function fakeEvalThrottleClear(string $key): int
    {
        $existed = $this->fakeThrottleExists($key) ? 1 : 0;

        unset($this->fakeThrottleStore[$key]);

        return $existed;
    }

    protected function fakeThrottleExists(string $key): bool
    {
        if (! isset($this->fakeThrottleStore[$key])) {
            return false;
        }

        return $this->fakeThrottleStore[$key]['expires_at'] > now()->timestamp;
    }

    protected function fakeThrottleGet(string $key): ?string
    {
        return $this->fakeThrottleExists($key) ? $this->fakeThrottleStore[$key]['value'] : null;
    }

    /** Mirrors real Redis TTL semantics: -2 for a missing/expired key. */
    protected function fakeThrottleTtl(string $key): int
    {
        if (! $this->fakeThrottleExists($key)) {
            return -2;
        }

        return max(1, $this->fakeThrottleStore[$key]['expires_at'] - now()->timestamp);
    }

    /**
     * Directly seeds the fake's store with a raw JSON payload and TTL,
     * bypassing set()'s validation entirely — for tests that need a
     * malformed/edge-case stored value that set() itself would never
     * produce (e.g. asserting resolve()'s handling of corrupt JSON).
     */
    protected function seedFakeThrottleRaw(string $key, string $rawValue, int $ttlSeconds): void
    {
        $this->fakeThrottleStore[$key] = [
            'value' => $rawValue,
            'expires_at' => now()->timestamp + $ttlSeconds,
        ];
    }

    protected function resetFakeTenantThrottleRedisStore(): void
    {
        $this->fakeThrottleStore = [];
    }
}
