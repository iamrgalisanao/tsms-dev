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

        // App\Services\CircuitBreaker's half-open admission/outcome/
        // transition paths (ADR T028a review findings F1/F2/F-NEW-1) run
        // as single Redis Lua script (EVAL) executions rather than a
        // sequence of separate commands, specifically so the whole
        // check-then-mutate operation is atomic. There is no real
        // Redis/Lua interpreter available under phpunit, so this fake
        // emulates the three known scripts' observable behavior directly
        // against the same in-memory store, matched by exact script text
        // (not argument shape, since the argument counts differ across
        // scripts anyway) so a reformulated or unrecognized script fails
        // loudly instead of silently producing wrong results.
        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                $key = $rest[0];
                $argv = array_slice($rest, $numKeys);

                if ($script === \App\Services\CircuitBreaker::HALF_OPEN_ADMISSION_SCRIPT) {
                    return $this->fakeEvalHalfOpenAdmission($key, $argv);
                }

                if ($script === \App\Services\CircuitBreaker::HALF_OPEN_OUTCOME_SCRIPT) {
                    return $this->fakeEvalHalfOpenOutcome($key, $argv);
                }

                if ($script === \App\Services\CircuitBreaker::HALF_OPEN_TRANSITION_SCRIPT) {
                    return $this->fakeEvalHalfOpenTransition($key, $argv);
                }

                throw new \RuntimeException('FakesCircuitBreakerRedis: unrecognized eval() script; add a fake for it.');
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
     * PHP re-implementation of App\Services\CircuitBreaker::HALF_OPEN_ADMISSION_SCRIPT,
     * operating on the same in-memory $fakeCircuitBreakerRedisStore array
     * the other fake commands above share, so it stays a true single-step
     * "atomic" mutation from the perspective of these synchronous tests
     * (no other fake command can interleave mid-array-write in PHP).
     *
     * ARGV: [now, resetTimeoutSeconds, maxProbes, closeThreshold, reopenThreshold, ttl]
     */
    protected function fakeEvalHalfOpenAdmission(string $key, array $argv): array
    {
        [$now, $resetTimeoutSeconds, $maxProbes, $closeThreshold, $reopenThreshold, $ttl] = array_map('intval', $argv);

        $row = &$this->fakeCircuitBreakerRedisStore[$key];
        $row ??= [];

        if (($row['state'] ?? null) !== 'half-open') {
            return ['not-half-open'];
        }

        $successes = (int) ($row['half_open_successes'] ?? 0);
        $failures = (int) ($row['half_open_failures'] ?? 0);
        $startedAt = (int) ($row['half_open_started_at'] ?? 0);
        $resolved = $successes >= $closeThreshold || $failures >= $reopenThreshold;

        if (!$resolved && $startedAt > 0 && ($now - $startedAt) >= $resetTimeoutSeconds) {
            $row['state'] = 'open';
            $row['opened_at'] = (string) $now;
            unset($ttl); // fake store has no real TTL/EXPIRE semantics to apply
            return ['expired'];
        }

        $admitted = (int) ($row['half_open_probe_count'] ?? 0) + 1;
        $row['half_open_probe_count'] = (string) $admitted;
        $generation = (int) ($row['half_open_generation'] ?? 0);

        if ($admitted > $maxProbes) {
            $row['half_open_probe_count'] = (string) ($admitted - 1);
            return ['rejected', $generation];
        }

        return ['admitted', $generation];
    }

    /**
     * PHP re-implementation of App\Services\CircuitBreaker::HALF_OPEN_OUTCOME_SCRIPT.
     * See fakeEvalHalfOpenAdmission()'s doc-comment for why this mirrors
     * the Lua script's logic directly against the shared in-memory store.
     *
     * ARGV: [generation, outcome, now, closeThreshold, reopenThreshold, ttl]
     */
    protected function fakeEvalHalfOpenOutcome(string $key, array $argv): array
    {
        [$generation, $outcome, $now, $closeThreshold, $reopenThreshold, $ttl] = $argv;
        $generation = (int) $generation;
        $now = (int) $now;
        $closeThreshold = (int) $closeThreshold;
        $reopenThreshold = (int) $reopenThreshold;
        unset($ttl); // fake store has no real TTL/EXPIRE semantics to apply

        $row = &$this->fakeCircuitBreakerRedisStore[$key];
        $row ??= [];

        $state = $row['state'] ?? '';
        $currentGeneration = (int) ($row['half_open_generation'] ?? 0);

        if ($state !== 'half-open' || $currentGeneration !== $generation) {
            return [0, $state, $currentGeneration];
        }

        $field = $outcome === 'failure' ? 'half_open_failures' : 'half_open_successes';
        $count = (int) ($row[$field] ?? 0) + 1;
        $row[$field] = (string) $count;

        $transitioned = 0;

        if ($outcome === 'failure') {
            $row['last_failure_at'] = (string) $now;
        }

        if ($outcome === 'success' && $count >= $closeThreshold) {
            $row['state'] = 'closed';
            $row['failure_count'] = '0';
            $row['opened_at'] = '0';
            $row['last_success_at'] = (string) $now;
            $transitioned = 1;
        } elseif ($outcome === 'failure' && $count >= $reopenThreshold) {
            $row['state'] = 'open';
            $row['opened_at'] = (string) $now;
            $transitioned = 2;
        }

        return [1, $count, $transitioned];
    }

    /**
     * PHP re-implementation of App\Services\CircuitBreaker::HALF_OPEN_TRANSITION_SCRIPT
     * (review finding F-NEW-1): a compare-and-set guarded open→half-open
     * transition. Only mutates the store when the observed `state` is
     * still 'open' and `resetTimeoutSeconds` has elapsed since 'opened_at'
     * — mirroring the Lua script's own guard read exactly, operating on
     * the same in-memory store the other fake commands share so it stays
     * a true single-step "atomic" mutation from the perspective of these
     * synchronous tests.
     *
     * ARGV: [now, resetTimeoutSeconds, ttl]
     */
    protected function fakeEvalHalfOpenTransition(string $key, array $argv): array
    {
        [$now, $resetTimeoutSeconds, $ttl] = array_map('intval', $argv);
        unset($ttl); // fake store has no real TTL/EXPIRE semantics to apply

        $row = &$this->fakeCircuitBreakerRedisStore[$key];
        $row ??= [];

        $state = $row['state'] ?? '';
        $openedAt = (int) ($row['opened_at'] ?? 0);

        if ($state !== 'open' || $openedAt <= 0 || ($now - $openedAt) < $resetTimeoutSeconds) {
            return ['not-open', $state];
        }

        $generation = (int) ($row['half_open_generation'] ?? 0) + 1;

        $row['half_open_generation'] = (string) $generation;
        $row['state'] = 'half-open';
        $row['half_open_started_at'] = (string) $now;
        $row['half_open_probe_count'] = '1';
        $row['half_open_successes'] = '0';
        $row['half_open_failures'] = '0';
        $row['failure_count'] = '0';

        return ['initialized', $generation];
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
