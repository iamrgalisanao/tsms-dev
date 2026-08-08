<?php

namespace Tests\Traits;

use App\Services\IngestionFairnessService;
use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade for App\Services\IngestionFairnessService (T044),
 * whose entire Redis footprint is a single atomic Lua script call
 * (FAIRNESS_CHECK_SCRIPT) that increments a fixed-window counter and,
 * only on the INCR that creates the key, applies an EXPIRE-equivalent TTL.
 *
 * Extracted from tests/Unit/IngestionFairnessServiceTest.php (T038) so both
 * that unit test and tests/Feature/IngestionFairnessTest.php exercise the
 * exact same Lua-simulation logic — avoiding the "one changes, the other
 * doesn't" drift this codebase has already worried about with
 * FakesCircuitBreakerRedis, which this trait otherwise mirrors the style of
 * (a Mockery double registered against Redis::shouldReceive('connection'),
 * backed by a plain in-memory array).
 *
 * IMPORTANT (review finding F2): the simulated Redis call path
 * (fakeFairnessRedis()'s eval() closure) must NOT contain hidden/implicit
 * assertions. It previously called $this->assertSame() on script identity
 * on EVERY simulated call, which silently multiplied the suite's assertion
 * count by call volume whenever a test drove a counter up to a real
 * configured limit (e.g. the global scope's limit of 10,000) instead of by
 * test count. Verify script identity ONCE per relevant test instead, via
 * assertFairnessScriptWasUsed() below. A future contributor extending this
 * fake should keep any new simulated command's happy-path free of
 * assertions for the same reason.
 */
trait FakesIngestionFairnessRedis
{
    /** @var array<string, int> */
    protected array $fakeFairnessRedisStore = [];

    /** @var array<string, int> */
    protected array $fakeFairnessExpireCallCounts = [];

    /** @var array<string, int> */
    protected array $fakeFairnessExpireSecondsSeen = [];

    /**
     * The most recent script text seen by fakeFairnessRedis()'s eval()
     * closure, recorded (not asserted) on every simulated call so
     * assertFairnessScriptWasUsed() can verify it once per test instead of
     * once per call (review finding F2). Null until at least one simulated
     * eval() call has happened.
     */
    protected ?string $fakeFairnessLastScriptSeen = null;

    /**
     * Fakes FAIRNESS_CHECK_SCRIPT's eval() call: INCR the fake counter, and
     * only when that INCR creates the key (result exactly 1), record an
     * EXPIRE-equivalent call (count + the actual seconds argument, so
     * tests can assert both "called exactly once" and "called with the
     * real configured window_seconds", per review finding F3). Returns the
     * post-increment count, matching the real script's return value.
     *
     * Deliberately does NOT assert anything in this closure (review finding
     * F2) — it only records the script seen, for assertFairnessScriptWasUsed()
     * to check once. See the trait-level doc-comment above.
     */
    protected function fakeFairnessRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) {
                $this->fakeFairnessLastScriptSeen = $script;

                $key = $rest[0];
                $windowSeconds = (int) $rest[$numKeys];

                $this->fakeFairnessRedisStore[$key] = ($this->fakeFairnessRedisStore[$key] ?? 0) + 1;
                $count = $this->fakeFairnessRedisStore[$key];

                if ($count === 1) {
                    $this->fakeFairnessExpireCallCounts[$key] = ($this->fakeFairnessExpireCallCounts[$key] ?? 0) + 1;
                    $this->fakeFairnessExpireSecondsSeen[$key] = $windowSeconds;
                }

                return $count;
            });

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Asserts, ONCE, that the simulated eval() call(s) made so far in the
     * current test used FAIRNESS_CHECK_SCRIPT — the one-time replacement
     * for the assertion that used to fire inside fakeFairnessRedis()'s
     * eval() closure on every single call (review finding F2). Call this
     * once at whatever point in a test it is meaningful to confirm the
     * right script was invoked. No-op-safe to call multiple times (it just
     * re-checks the same recorded value), but should never be called
     * inside a loop keyed to call volume.
     */
    protected function assertFairnessScriptWasUsed(): void
    {
        $this->assertSame(
            IngestionFairnessService::FAIRNESS_CHECK_SCRIPT,
            $this->fakeFairnessLastScriptSeen,
            'unrecognized eval() script; the fake only understands FAIRNESS_CHECK_SCRIPT'
        );
    }

    /**
     * Directly seeds the fake's internal window counter for a given
     * fairness Redis key, bypassing the simulated eval() call entirely —
     * mirroring FakesCircuitBreakerRedis's direct state-seeding pattern
     * (e.g. its fakeEvalHalfOpenTransition()-style helpers write straight
     * into the shared in-memory store instead of replaying the Lua
     * simulation call-by-call).
     *
     * This lets a test jump straight to "one below the configured limit"
     * instead of looping a real simulated check() call up to the actual
     * configured limit (review finding F1) — which, at this codebase's
     * real limits (200/50/10,000 for tenant/terminal/global), turned a
     * single boundary scenario into hundreds or thousands of simulated
     * Redis round-trips.
     *
     * Callers are responsible for constructing $key in EXACTLY the same
     * format IngestionFairnessService::check() will compute
     * (`{key_prefix}{scope}:{scope_id}:{window}`, where {window} is
     * `intdiv(now, window_seconds)` at the SAME Carbon::setTestNow()-frozen
     * "now" the test is using) so the seeded count is visible to the real
     * service call that follows.
     */
    protected function seedFairnessCount(string $key, int $count): void
    {
        $this->fakeFairnessRedisStore[$key] = $count;
    }

    /**
     * Simulate a full Redis outage: connection() resolution itself throws.
     */
    protected function fakeBrokenFairnessRedis(string $connection = 'default'): void
    {
        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andThrow(new \RuntimeException('Redis connection refused (simulated outage)'));
    }

    /**
     * Simulates the atomic script itself failing (as opposed to
     * fakeBrokenFairnessRedis()'s total connection outage): connection()
     * resolves fine, but the single eval() call throws. Since
     * FAIRNESS_CHECK_SCRIPT folds INCR+EXPIRE into one Redis round-trip,
     * this is the only possible failure mode for the check — there is no
     * "half-succeeded" state to simulate (review finding F1/F2).
     */
    protected function fakeFairnessRedisWithFailingEval(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andThrow(new \RuntimeException('Redis EVAL failed (simulated script failure)'));

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Peek at the raw fake-store increment count for a given fairness Redis
     * key, for assertions that don't go through the public
     * IngestionFairnessService API (e.g. proving no cross-key
     * contamination between scopes).
     */
    protected function fakeFairnessRedisCount(string $key): int
    {
        return $this->fakeFairnessRedisStore[$key] ?? 0;
    }

    protected function resetFakeFairnessRedisStore(): void
    {
        $this->fakeFairnessRedisStore = [];
        $this->fakeFairnessExpireCallCounts = [];
        $this->fakeFairnessExpireSecondsSeen = [];
        $this->fakeFairnessLastScriptSeen = null;
    }
}
