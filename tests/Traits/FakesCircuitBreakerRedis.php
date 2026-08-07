<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade with an in-memory hash store so
 * App\Services\CircuitBreaker's Redis-backed state machine can be exercised
 * without a real Redis server (CI provisions no Redis service).
 *
 * The fake store is a plain PHP array keyed by the full Redis hash key
 * (e.g. "tsms:circuit-breaker:transaction-intake"), each value itself an
 * associative array of hash fields, mirroring HSET/HGETALL/HINCRBY/EXPIRE
 * semantics closely enough for CircuitBreaker's needs.
 */
trait FakesCircuitBreakerRedis
{
    /** @var array<string, array<string, mixed>> */
    protected array $fakeCircuitBreakerRedisStore = [];

    /** @var array<string, \Mockery\MockInterface> */
    protected array $fakeCircuitBreakerRedisDoubles = [];

    /**
     * Wire up a working in-memory Redis double for the given connection name
     * (defaults to the 'default' connection, matching
     * tsms.circuit_breaker.redis_connection's default).
     */
    protected function fakeCircuitBreakerRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();
        $this->fakeCircuitBreakerRedisDoubles[$connection] = $double;

        $double->shouldReceive('hset')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key, string $field, $value) {
                $this->fakeCircuitBreakerRedisStore[$key][$field] = (string) $value;
                return 1;
            });

        $double->shouldReceive('hincrby')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key, string $field, int $increment) {
                $current = (int) ($this->fakeCircuitBreakerRedisStore[$key][$field] ?? 0);
                $current += $increment;
                $this->fakeCircuitBreakerRedisStore[$key][$field] = (string) $current;
                return $current;
            });

        $double->shouldReceive('hgetall')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key) {
                return $this->fakeCircuitBreakerRedisStore[$key] ?? [];
            });

        $double->shouldReceive('hget')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key, string $field) {
                return $this->fakeCircuitBreakerRedisStore[$key][$field] ?? null;
            });

        $double->shouldReceive('expire')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        // CircuitBreaker::recordFailure()/reset() batch their hset/hincrby/
        // expire calls into a single Redis::connection()->pipeline(...) or
        // ->transaction(...) round-trip (see app/Services/CircuitBreaker.php).
        // The fake pipe/transaction context is simply this same double —
        // every command the callback issues against it is recorded into the
        // same in-memory store above, which is all these tests need to
        // assert on.
        $double->shouldReceive('pipeline')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (callable $callback) use ($double) {
                return $callback($double);
            });

        $double->shouldReceive('transaction')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (callable $callback) use ($double) {
                return $callback($double);
            });

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Simulate a full Redis outage: any CircuitBreaker Redis call throws.
     */
    protected function breakCircuitBreakerRedisConnection(string $connection = 'default'): void
    {
        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andThrow(new \RuntimeException('Redis connection refused (simulated outage)'));
    }

    /**
     * Peek at the raw fake-store state for a given circuit-breaker Redis key,
     * for assertions that don't go through the public CircuitBreaker API.
     */
    protected function fakeCircuitBreakerRedisState(string $key): array
    {
        return $this->fakeCircuitBreakerRedisStore[$key] ?? [];
    }

    protected function resetFakeCircuitBreakerRedisStore(): void
    {
        $this->fakeCircuitBreakerRedisStore = [];
    }

    /**
     * Add an extra method expectation (e.g. 'llen', for
     * IngestionBackpressureService's queue-depth checks) onto the same
     * connection('default') double already wired up by
     * fakeCircuitBreakerRedis(), so both concerns can share one mocked
     * Redis::connection() resolution instead of racing two competing
     * doubles registered for the same facade call.
     */
    protected function fakeCircuitBreakerRedisDouble(string $connection = 'default'): \Mockery\MockInterface
    {
        return $this->fakeCircuitBreakerRedisDoubles[$connection];
    }
}
