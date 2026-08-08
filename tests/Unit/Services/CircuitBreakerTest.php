<?php

namespace Tests\Unit\Services;

use App\Services\CircuitBreaker;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

class CircuitBreakerTest extends TestCase
{
    use FakesCircuitBreakerRedis;

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

    public function test_new_breaker_is_closed_and_available(): void
    {
        $breaker = new CircuitBreaker('svc-a');

        $this->assertTrue($breaker->isAvailable());
    }

    public function test_failures_below_threshold_do_not_open_the_circuit(): void
    {
        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordFailure();
        $breaker->recordFailure(); // threshold is 3, this is below it

        $this->assertTrue($breaker->isAvailable());
    }

    public function test_reaching_failure_threshold_opens_the_circuit(): void
    {
        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure(); // reaches threshold of 3

        $this->assertFalse($breaker->isAvailable());
    }

    public function test_open_circuit_blocks_requests_within_reset_timeout(): void
    {
        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        // Immediately re-check, well within the 60s reset timeout.
        $this->assertFalse($breaker->isAvailable());
    }

    public function test_open_circuit_transitions_to_half_open_after_reset_timeout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $breaker = new CircuitBreaker('svc-a');
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertFalse($breaker->isAvailable());

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));

        $this->assertTrue($breaker->isAvailable());

        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:svc-a');
        $this->assertSame(CircuitBreaker::STATE_HALF_OPEN, $state['state']);
    }

    public function test_half_open_allows_requests_through(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $breaker = new CircuitBreaker('svc-a');
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));
        $this->assertTrue($breaker->isAvailable()); // triggers the half-open transition

        // Subsequent checks while half-open should also pass through.
        $this->assertTrue($breaker->isAvailable());
    }

    public function test_success_in_half_open_closes_circuit_and_resets_failure_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $breaker = new CircuitBreaker('svc-a');
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));
        $this->assertTrue($breaker->isAvailable()); // now half-open

        $breaker->recordSuccess();

        $state = $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:svc-a');
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state']);
        $this->assertSame('0', (string) $state['failure_count']);
        $this->assertTrue($breaker->isAvailable());
    }

    public function test_failure_in_half_open_reopens_circuit(): void
    {
        // Updated for T028a's 2-of-3 half-open majority semantics (see
        // specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md):
        // transitionToHalfOpen() now resets failure_count to 0, and a
        // single failure no longer reopens the breaker outright — it takes
        // 2 of up to 3 generation-scoped probe failures, recorded via the
        // recordFailure(?int $generation) parameter that real half-open
        // probes (via CircuitBreakerMiddleware) thread through.
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $breaker = new CircuitBreaker('svc-a');
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00')->addSeconds(61));
        $this->assertTrue($breaker->isAvailable()); // now half-open, admits probe 1
        $generation = $breaker->currentHalfOpenGeneration();

        $breaker->recordFailure($generation); // 1st of 2 required failures
        $this->assertTrue($breaker->isAvailable()); // still half-open, admits probe 2

        $breaker->recordFailure($generation); // 2nd of 2 required failures reopens
        $this->assertFalse($breaker->isAvailable());
    }

    public function test_success_in_closed_state_is_a_noop(): void
    {
        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordSuccess();

        // No Redis write should have occurred for an already-closed breaker.
        $this->assertSame([], $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:svc-a'));
        $this->assertTrue($breaker->isAvailable());
    }

    public function test_isAvailable_fails_open_when_redis_read_throws(): void
    {
        $this->breakCircuitBreakerRedisConnection();

        $breaker = new CircuitBreaker('svc-a');

        $this->assertTrue($breaker->isAvailable());
    }

    public function test_recordFailure_swallows_redis_write_exception_without_throwing(): void
    {
        $this->breakCircuitBreakerRedisConnection();

        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordFailure();

        $this->assertTrue(true); // reaching this line means no exception propagated
    }

    public function test_disabled_via_config_is_always_available_and_records_are_noop(): void
    {
        config()->set('tsms.circuit_breaker.enabled', false);

        $breaker = new CircuitBreaker('svc-a');

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertTrue($breaker->isAvailable());
        $this->assertSame([], $this->fakeCircuitBreakerRedisState('tsms:circuit-breaker:svc-a'));
    }

    public function test_state_is_shared_across_separate_instances_for_the_same_service_key(): void
    {
        $breakerA = new CircuitBreaker('transaction-intake');
        $breakerB = new CircuitBreaker('transaction-intake');

        $breakerA->recordFailure();
        $breakerA->recordFailure();
        $breakerA->recordFailure();

        $this->assertFalse($breakerB->isAvailable());
    }

    public function test_different_service_keys_do_not_share_state(): void
    {
        $breakerA = new CircuitBreaker('svc-a');
        $breakerB = new CircuitBreaker('svc-b');

        $breakerA->recordFailure();
        $breakerA->recordFailure();
        $breakerA->recordFailure();

        $this->assertFalse($breakerA->isAvailable());
        $this->assertTrue($breakerB->isAvailable());
    }
}
