<?php

namespace Tests\Unit;

use App\Services\CircuitBreaker;
use App\Support\Metrics;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

/**
 * Unit tests for the real Redis-backed App\Services\CircuitBreaker,
 * covering the bounded-concurrent-probe half-open semantics decided in
 * specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md
 * (N=3 max concurrent probes per generation, close on 2-of-3 successes,
 * reopen on 2-of-3 failures, half_open_generation-based staleness
 * discarding, and expired-episode handling).
 *
 * Does NOT touch tests/Unit/CircuitBreakerTest.php, which covers the
 * unrelated legacy DB-backed App\Models\CircuitBreaker.
 */
class RedisCircuitBreakerTest extends TestCase
{
    use FakesCircuitBreakerRedis;

    private const SERVICE_KEY = 'redis-cb-test';
    private const KEY = 'tsms:circuit-breaker:' . self::SERVICE_KEY;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.redis_connection', 'default');
        config()->set('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:');
        config()->set('tsms.circuit_breaker.failure_threshold', 3);
        config()->set('tsms.circuit_breaker.reset_timeout_seconds', 60);
        config()->set('tsms.circuit_breaker.state_ttl_seconds', 3600);

        $this->fakeCircuitBreakerRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Seed the fake Redis hash directly into an in-progress half-open
     * episode, bypassing the protected transitionToHalfOpen() so each test
     * can start from a precise, known state.
     */
    private function seedHalfOpen(array $overrides = []): void
    {
        $this->fakeCircuitBreakerRedisStore[self::KEY] = array_merge([
            'state' => CircuitBreaker::STATE_HALF_OPEN,
            'failure_count' => '0',
            'opened_at' => '0',
            'half_open_generation' => '1',
            'half_open_started_at' => (string) now()->timestamp,
            'half_open_probe_count' => '0',
            'half_open_successes' => '0',
            'half_open_failures' => '0',
        ], $overrides);
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(self::SERVICE_KEY);
    }

    private function state(): array
    {
        $data = $this->fakeCircuitBreakerRedisState(self::KEY);

        return [
            'state' => $data['state'] ?? CircuitBreaker::STATE_CLOSED,
            'half_open_generation' => (int) ($data['half_open_generation'] ?? 0),
            'half_open_probe_count' => (int) ($data['half_open_probe_count'] ?? 0),
            'half_open_successes' => (int) ($data['half_open_successes'] ?? 0),
            'half_open_failures' => (int) ($data['half_open_failures'] ?? 0),
            'failure_count' => (int) ($data['failure_count'] ?? 0),
            'opened_at' => (int) ($data['opened_at'] ?? 0),
        ];
    }

    public function test_admission_is_capped_at_three_concurrent_probes_and_self_corrects(): void
    {
        $this->seedHalfOpen();
        $cb = $this->breaker();

        $this->assertTrue($cb->isAvailable(), 'probe 1 should be admitted');
        $this->assertSame(1, $this->state()['half_open_probe_count']);

        $this->assertTrue($cb->isAvailable(), 'probe 2 should be admitted');
        $this->assertSame(2, $this->state()['half_open_probe_count']);

        $this->assertTrue($cb->isAvailable(), 'probe 3 should be admitted');
        $this->assertSame(3, $this->state()['half_open_probe_count']);

        $this->assertFalse($cb->isAvailable(), 'probe 4 must be rejected (over the N=3 cap)');
        $this->assertNull($cb->currentHalfOpenGeneration());

        // The 4th attempt's own increment must be self-corrected back down
        // so the counter still reflects only the 3 genuinely admitted probes.
        $this->assertSame(3, $this->state()['half_open_probe_count']);
    }

    public function test_breaker_closes_exactly_on_the_second_of_three_successes(): void
    {
        $this->seedHalfOpen();
        $cb = $this->breaker();

        $cb->recordSuccess(1);
        $state = $this->state();
        $this->assertSame(1, $state['half_open_successes']);
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state'], 'must not close after only 1 of 2 required successes');

        $cb->recordSuccess(1);
        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state']);
        $this->assertSame(0, $state['failure_count']);
        $this->assertSame(0, $state['opened_at']);

        // WU4 (T053 remainder): the closed transition is mirrored into Metrics.
        $this->assertSame(
            CircuitBreaker::STATE_METRIC_CLOSED,
            Metrics::get('circuit_breaker.state.'.self::SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED)
        );
    }

    public function test_breaker_reopens_exactly_on_the_second_of_three_failures(): void
    {
        $this->seedHalfOpen();
        $cb = $this->breaker();

        $cb->recordFailure(1);
        $state = $this->state();
        $this->assertSame(1, $state['half_open_failures']);
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state'], 'must not reopen after only 1 of 2 required failures');

        $cb->recordFailure(1);
        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state']);
        $this->assertGreaterThan(0, $state['opened_at']);

        // WU4 (T053 remainder): the reopen transition is mirrored into Metrics.
        $this->assertSame(
            CircuitBreaker::STATE_METRIC_OPEN,
            Metrics::get('circuit_breaker.state.'.self::SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED)
        );
    }

    /**
     * WU4 (T053 remainder): current breaker state must be readable via
     * Metrics::get() without parsing logs, at every real transition —
     * closed-by-threshold-open, and open-to-half-open. (closed-via-2-of-3
     * and reopen-via-2-of-3 are covered by the two tests immediately
     * above.)
     */
    public function test_breaker_state_gauge_reflects_open_and_half_open_transitions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $cb = $this->breaker();

        // Fresh breaker, never transitioned: absence of a written gauge
        // value must read as closed (this class's own default state).
        $this->assertSame(
            CircuitBreaker::STATE_METRIC_CLOSED,
            Metrics::get('circuit_breaker.state.'.self::SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED)
        );

        // failure_threshold is configured to 3 in setUp().
        $cb->recordFailure();
        $cb->recordFailure();
        $cb->recordFailure();

        $this->assertSame(CircuitBreaker::STATE_OPEN, $this->state()['state']);
        $this->assertSame(
            CircuitBreaker::STATE_METRIC_OPEN,
            Metrics::get('circuit_breaker.state.'.self::SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED)
        );

        // Advance past reset_timeout_seconds (60) so the next isAvailable()
        // call performs the real open->half-open transition.
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertTrue($cb->isAvailable());

        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $this->state()['state']);
        $this->assertSame(
            CircuitBreaker::STATE_METRIC_HALF_OPEN,
            Metrics::get('circuit_breaker.state.'.self::SERVICE_KEY, CircuitBreaker::STATE_METRIC_CLOSED)
        );
    }

    public function test_late_failure_after_close_is_ignored(): void
    {
        $this->seedHalfOpen();
        $cb = $this->breaker();

        $cb->recordSuccess(1);
        $cb->recordSuccess(1);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $this->state()['state']);

        // A third, slow probe's failure arrives after the circuit is
        // already closed via 2-of-3 successes — must be discarded entirely.
        $cb->recordFailure(1);

        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state'], 'a late failure must not reopen an already-closed breaker');
        $this->assertSame(0, $state['half_open_failures'], 'discarded outcomes must not mutate any counter');
        $this->assertSame(0, $state['failure_count']);
    }

    public function test_late_success_after_reopen_is_ignored(): void
    {
        $this->seedHalfOpen();
        $cb = $this->breaker();

        $cb->recordFailure(1);
        $cb->recordFailure(1);
        $this->assertSame(CircuitBreaker::STATE_OPEN, $this->state()['state']);

        // A third, slow probe's success arrives after the circuit is
        // already reopened via 2-of-3 failures — must be discarded entirely.
        $cb->recordSuccess(1);

        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state'], 'a late success must not close an already-reopened breaker');
        $this->assertSame(0, $state['half_open_successes'], 'discarded outcomes must not mutate any counter');
    }

    public function test_stale_generation_outcome_is_ignored(): void
    {
        // Simulate that a new half-open episode (generation 2) has already
        // started while a probe admitted under generation 1 is still in
        // flight.
        $this->seedHalfOpen(['half_open_generation' => '2']);
        $cb = $this->breaker();

        $cb->recordSuccess(1); // stale generation
        $cb->recordFailure(1); // stale generation

        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state']);
        $this->assertSame(2, $state['half_open_generation'], 'current generation must be untouched');
        $this->assertSame(0, $state['half_open_successes']);
        $this->assertSame(0, $state['half_open_failures']);
    }

    public function test_expired_half_open_cycle_reopens_then_starts_a_fresh_generation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        // Half-open episode started 61s ago (past the 60s reset timeout)
        // and never resolved (no 2-of-3 either way) — e.g. because a
        // probe was abandoned and never reported an outcome.
        $this->seedHalfOpen([
            'half_open_started_at' => (string) Carbon::now()->subSeconds(61)->timestamp,
            'half_open_probe_count' => '1',
        ]);
        $cb = $this->breaker();

        // The next observer sees the expired, unresolved episode and drives
        // the breaker back through 'open' rather than reusing generation 1.
        $this->assertFalse($cb->isAvailable());
        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state']);
        $this->assertSame(Carbon::now()->timestamp, $state['opened_at']);

        // Still within the fresh reset window: stays open.
        $this->assertFalse($cb->isAvailable());

        // Advance past the reset timeout again to re-enter half-open.
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertTrue($cb->isAvailable());
        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state']);
        $this->assertSame(2, $state['half_open_generation'], 'a fresh, bumped generation must be started, not the superseded one');
        $this->assertSame(2, $cb->currentHalfOpenGeneration());

        // The abandoned generation-1 probe's outcome (if it ever arrives)
        // must not corrupt the new generation's counters.
        $cb->recordSuccess(1);
        $this->assertSame(0, $this->state()['half_open_successes']);
    }

    /**
     * NOTE: this test only exercises two sequential calls in the same
     * thread — it does NOT exercise real concurrency (no parallel
     * threads/processes are involved). It was previously named
     * test_concurrent_recordSuccess_calls_transition_idempotently_without_corruption,
     * which implied genuine concurrency; renamed per code review to
     * accurately describe what it actually tests: that a duplicate
     * recordSuccess() call arriving after the breaker has already
     * transitioned is a safe no-op. See
     * test_admission_after_generation_already_rolled_over_joins_the_current_generation()
     * and test_success_recorded_against_a_generation_that_already_rolled_over_is_discarded()
     * below for further state-outcome coverage of the generation-safety
     * rules added for review findings F1/F2. NONE of these tests —
     * including this one — exercise or prove genuine concurrent/atomic
     * execution; a single opaque eval()/Lua call cannot be interleaved
     * from the PHP test layer at all. The atomicity guarantee itself
     * rests entirely on the Lua scripts' own single, non-preemptible
     * server-side execution (Redis serializes EVAL), not on anything a
     * synchronous PHPUnit test against a fake can demonstrate. See
     * test_losing_caller_in_open_to_half_open_transition_joins_winning_generation_instead_of_clobbering_it()
     * (review finding F-NEW-1) for the closest this suite gets to
     * exercising the race scenario, and its docblock for exactly what
     * that test does and does not prove.
     */
    public function test_duplicate_recordSuccess_after_transition_is_a_safe_noop(): void
    {
        // Simulate the 2nd of 3 probes already having succeeded.
        $this->seedHalfOpen(['half_open_successes' => '1']);
        $cb = $this->breaker();

        // First call observes the 2nd success and closes.
        $cb->recordSuccess(1);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $this->state()['state']);

        // A second, duplicate call for the same generation arrives just
        // after — since state/generation has already moved on, this must
        // be discarded rather than double-transitioning or erroring.
        $cb->recordSuccess(1);

        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state'], 'must remain closed, not corrupted by the duplicate call');
        $this->assertSame(0, $state['failure_count']);
    }

    /**
     * Review finding F1: recordSuccess()/recordFailure() previously did a
     * non-atomic read-then-write sequence (HGETALL, compare generation,
     * then a separate HINCRBY). This test does NOT simulate concurrency
     * or interleaving — it pre-sets the fake Redis store to the state a
     * rolled-over generation would leave behind (as if another process's
     * transitionToHalfOpen() had already completed, in full, before this
     * test's recordSuccess() call runs) and then asserts what
     * HALF_OPEN_OUTCOME_SCRIPT does when it observes that state: it is a
     * "given this pre-set state, the script produces this outcome" test,
     * not a proof that the operation is atomic under a real race — that
     * property rests solely on the Lua script executing as one
     * non-preemptible server-side unit, which no PHP-level test can
     * itself demonstrate. Asserts zero mutation of any kind occurs
     * against the new generation.
     */
    public function test_success_recorded_against_a_generation_that_already_rolled_over_is_discarded(): void
    {
        $this->seedHalfOpen(); // generation 1
        $cb = $this->breaker();

        // Admit a probe under generation 1.
        $this->assertTrue($cb->isAvailable());
        $this->assertSame(1, $cb->currentHalfOpenGeneration());

        // Simulate another process's transitionToHalfOpen() landing while
        // the generation-1 probe above is still in flight: the hash has
        // already rolled over to a fresh generation 2, with generation 2's
        // own counters, by the time the stale probe's outcome arrives.
        $this->fakeCircuitBreakerRedisStore[self::KEY] = array_merge(
            $this->fakeCircuitBreakerRedisStore[self::KEY],
            [
                'half_open_generation' => '2',
                'half_open_started_at' => (string) now()->timestamp,
                'half_open_probe_count' => '1',
                'half_open_successes' => '0',
                'half_open_failures' => '0',
                'failure_count' => '0',
            ]
        );

        // The stale generation-1 probe's success arrives after the rollover.
        $cb->recordSuccess(1);

        $state = $this->state();
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state'], 'must remain half-open, not closed by the stale outcome');
        $this->assertSame(2, $state['half_open_generation'], 'current generation must be untouched');
        $this->assertSame(0, $state['half_open_successes'], 'the new generation counters must be entirely unaffected — zero mutation for a discarded outcome');
        $this->assertSame(0, $state['half_open_failures']);
    }

    /**
     * Review finding F2: isAvailable()'s admission path previously read
     * state (including half_open_generation) once, then separately
     * performed the HINCRBY half_open_probe_count admission, and returned
     * the generation from the *original* read rather than whichever
     * generation the increment actually landed against. This test does
     * NOT simulate concurrency or interleaving — it pre-sets the fake
     * Redis store directly to a state representing "generation 2 is
     * already live, with one probe already admitted" (as if another
     * worker's transitionToHalfOpen() and first admission had already
     * happened, in full, before this test's isAvailable() call runs) and
     * asserts what HALF_OPEN_ADMISSION_SCRIPT does when it observes that
     * state: the generation returned to the caller exactly matches the
     * generation the probe slot was actually consumed against, with
     * correct (not off-by-one, not double-counted, not misattributed)
     * capacity accounting for that generation. It is a "given this
     * pre-set state, the script produces this outcome" test, not a proof
     * of atomicity under a real race — that property rests solely on the
     * Lua script executing as one non-preemptible server-side unit.
     */
    public function test_admission_after_generation_already_rolled_over_joins_the_current_generation(): void
    {
        // By the time this admission attempt's atomic operation actually
        // executes, generation 2 is already the live state — e.g. another
        // worker's transitionToHalfOpen() already landed, with one probe
        // of its own already admitted.
        $this->seedHalfOpen([
            'half_open_generation' => '2',
            'half_open_probe_count' => '1',
        ]);
        $cb = $this->breaker();

        $this->assertTrue($cb->isAvailable(), 'the probe must still be admitted, against the now-current generation');
        $this->assertSame(2, $cb->currentHalfOpenGeneration(), 'the returned generation must exactly match the generation the slot was actually consumed against, not a stale value');

        $state = $this->state();
        $this->assertSame(2, $state['half_open_generation']);
        $this->assertSame(2, $state['half_open_probe_count'], 'capacity accounting for generation 2 must be exactly incremented by this one admission: not off-by-one, not double-counted, not misattributed to generation 1');
    }

    /**
     * Review finding F-NEW-1: the open→half-open transition
     * (transitionToHalfOpen(), backing isAvailable()'s open-state branch)
     * was previously an unguarded, non-atomic sequence (a plain HGETALL,
     * a standalone HINCRBY of half_open_generation, then a separate
     * MULTI/EXEC that unconditionally wrote state/counters) — so two
     * concurrent callers who both observed state==='open'-and-expired
     * could each run the sequence, and whichever's MULTI/EXEC landed
     * second would overwrite the first caller's already-admitted probe
     * slot and zero the counters, permanently losing that probe.
     *
     * HONESTY NOTE on what this test does and does not prove: real
     * concurrent execution cannot be constructed in a synchronous
     * PHPUnit test against a fake — there is no way to make two threads
     * or processes actually race inside this test. What this test DOES
     * prove is the OBSERVABLE GUARANTEE that makes concurrent safety
     * possible: it invokes the protected transitionToHalfOpen() method
     * directly, twice in sequence, simulating two callers who
     * independently decided (from their own, possibly-stale outer reads)
     * to attempt the transition while state was 'open' and expired. The
     * first invocation performs the real initialization; the second
     * invocation — representing whichever caller's atomic script
     * execution happens to run second, which is guaranteed to be *some*
     * caller given Redis serializes EVAL execution — must observe the
     * already-half-open state and refuse to re-initialize or clobber it,
     * then correctly fold into the existing generation via the ordinary
     * half-open admission path. This test does not, and cannot, prove
     * that Redis actually serializes two truly-simultaneous EVAL calls
     * this way — that guarantee rests entirely on Redis's own execution
     * model and on HALF_OPEN_TRANSITION_SCRIPT being a single Lua script
     * (one non-preemptible server-side execution), not on anything
     * demonstrated here.
     */
    public function test_losing_caller_in_open_to_half_open_transition_joins_winning_generation_instead_of_clobbering_it(): void
    {
        // Seed a genuinely OPEN, expired episode — the precondition under
        // which isAvailable() invokes transitionToHalfOpen().
        $this->fakeCircuitBreakerRedisStore[self::KEY] = [
            'state' => CircuitBreaker::STATE_OPEN,
            'failure_count' => '5',
            'opened_at' => (string) now()->subSeconds(61)->timestamp,
            'half_open_generation' => '0',
            'half_open_started_at' => '0',
            'half_open_probe_count' => '0',
            'half_open_successes' => '0',
            'half_open_failures' => '0',
        ];

        $cb1 = $this->breaker();
        $cb2 = $this->breaker();

        // Caller 1's script invocation is (by construction of this test)
        // the first to actually run: it observes state still 'open' and
        // expired, so it performs the one real initialization — bumps
        // the generation, resets counters/failure_count, and admits
        // itself as the generation's first probe, all atomically.
        $generation1 = $this->invokeTransitionToHalfOpen($cb1);
        $this->assertSame(1, $generation1, 'the caller whose script runs first performs the one real initialization');

        $state = $this->state();
        $this->assertSame(1, $state['half_open_generation'], 'exactly one generation increment must occur, not two');
        $this->assertSame(1, $state['half_open_probe_count'], 'the initiator is admitted as probe #1 within the same atomic operation');
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state']);
        $this->assertSame(0, $state['failure_count'], 'failure_count must be reset on entry to half-open');

        // Caller 2's script invocation runs second and must observe the
        // already-half-open state: no re-initialization, no clobbering of
        // caller 1's committed generation or probe count.
        $generation2 = $this->invokeTransitionToHalfOpen($cb2);
        $this->assertNull($generation2, 'a caller whose script runs after the winner must not re-initialize/clobber the winning generation');

        $state = $this->state();
        $this->assertSame(1, $state['half_open_generation'], 'still exactly one generation increment after the losing caller\'s no-op attempt');
        $this->assertSame(1, $state['half_open_probe_count'], 'the losing caller\'s attempt must not itself consume or corrupt a probe slot');

        // isAvailable()'s actual fallback for a caller whose transition
        // attempt lost the race is to fall through to the ordinary
        // half-open admission path (HALF_OPEN_ADMISSION_SCRIPT) — the
        // same path any other request arriving while already half-open
        // takes — admitting it against the now-current generation instead
        // of silently dropping it.
        $this->assertTrue($cb2->isAvailable(), 'the losing caller must still be admitted, folded into the current generation, not silently dropped');
        $this->assertSame(1, $cb2->currentHalfOpenGeneration(), 'both callers end up holding the same, valid, non-conflicting generation');

        $state = $this->state();
        $this->assertSame(1, $state['half_open_generation'], 'still exactly one generation increment overall');
        $this->assertSame(2, $state['half_open_probe_count'], 'both callers accounted for: the initiator (probe 1) plus the joined admission (probe 2)');
    }

    /**
     * Invoke the protected CircuitBreaker::transitionToHalfOpen() method
     * directly. Used only by
     * test_losing_caller_in_open_to_half_open_transition_joins_winning_generation_instead_of_clobbering_it()
     * to simulate two independent callers each attempting the atomic
     * open→half-open transition, since isAvailable()'s own outer state
     * check would otherwise route a second, sequential call down the
     * ordinary half-open branch before ever reaching
     * transitionToHalfOpen() again.
     */
    private function invokeTransitionToHalfOpen(CircuitBreaker $cb): ?int
    {
        $method = new \ReflectionMethod(CircuitBreaker::class, 'transitionToHalfOpen');
        $method->setAccessible(true);

        return $method->invoke($cb);
    }
}
