<?php

namespace Tests\Unit;

use App\Services\IngestionFairnessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesIngestionFairnessRedis;

/**
 * Unit tests for App\Services\IngestionFairnessService (T044): a
 * Redis-backed fixed-window fairness limiter for the global, tenant, and
 * terminal admission scopes, whose INCR+conditional-EXPIRE is executed as
 * a single atomic Lua script (FAIRNESS_CHECK_SCRIPT) rather than two
 * separate Redis round-trips (review finding F1).
 *
 * Redis is faked via tests/Traits/FakesIngestionFairnessRedis (extracted
 * during T038 so this unit test and tests/Feature/IngestionFairnessTest.php
 * share one Lua-simulation implementation instead of two that could drift):
 * a Mockery double registered against Redis::shouldReceive('connection'),
 * backed by a plain in-memory array, with a fake eval() that replicates
 * FAIRNESS_CHECK_SCRIPT's own logic (increment a fake counter, set a fake
 * TTL only on first creation, return the resulting count) so the atomic
 * script's observable behavior can be exercised without a real Redis/Lua
 * interpreter under phpunit.
 */
class IngestionFairnessServiceTest extends TestCase
{
    use FakesIngestionFairnessRedis;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.fairness.redis_connection', 'default');
        config()->set('tsms.fairness.key_prefix', 'fairness:');
        config()->set('tsms.fairness.window_seconds', 60);
        config()->set('tsms.fairness.global.limit', 5);
        config()->set('tsms.fairness.tenant.limit', 3);
        config()->set('tsms.fairness.terminal.limit', 2);

        $this->resetFakeFairnessRedisStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_first_request_in_a_fresh_window_is_allowed(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $result = $service->checkTenant(1);

        $this->assertTrue($result['allowed']);
        $this->assertSame('tenant', $result['scope']);
        $this->assertSame(3, $result['limit']);
        $this->assertSame(1, $result['count']);
    }

    public function test_count_exactly_at_the_configured_limit_is_still_allowed(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        // terminal limit is configured to 2: the 1st and 2nd checks must
        // both be allowed, confirming the boundary is "count <= limit
        // allowed", not "count < limit allowed".
        $first = $service->checkTerminal(10);
        $second = $service->checkTerminal(10);

        $this->assertTrue($first['allowed']);
        $this->assertSame(1, $first['count']);

        $this->assertTrue($second['allowed'], 'a count exactly equal to the configured limit must still be allowed');
        $this->assertSame(2, $second['count']);
    }

    public function test_count_exceeding_the_configured_limit_is_rejected_with_retry_after(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        // terminal limit is 2: 3rd request in the same window must be
        // rejected.
        $service->checkTerminal(20);
        $service->checkTerminal(20);
        $third = $service->checkTerminal(20);

        $this->assertFalse($third['allowed']);
        $this->assertSame(3, $third['count']);
        $this->assertIsInt($third['retry_after_seconds']);
        $this->assertGreaterThanOrEqual(1, $third['retry_after_seconds']);
    }

    public function test_tenant_counters_are_isolated_between_different_tenants(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        // Exhaust tenant A's limit (3).
        $service->checkTenant(100);
        $service->checkTenant(100);
        $exhausted = $service->checkTenant(100);
        $this->assertTrue($exhausted['allowed']);

        $rejectedForA = $service->checkTenant(100);
        $this->assertFalse($rejectedForA['allowed'], 'tenant A must now be over its own limit');

        // Tenant B's counter must be completely unaffected by tenant A's
        // exhausted counter.
        $resultForB = $service->checkTenant(200);
        $this->assertTrue($resultForB['allowed'], 'a different tenant must not be affected by tenant A hitting its limit');
        $this->assertSame(1, $resultForB['count']);
    }

    public function test_tenant_and_terminal_scopes_are_isolated_from_each_other(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        // Exhaust tenant 300's limit (3) and push it into rejection.
        $service->checkTenant(300);
        $service->checkTenant(300);
        $service->checkTenant(300);
        $rejectedTenant = $service->checkTenant(300);
        $this->assertFalse($rejectedTenant['allowed']);

        // A terminal-scope check (even using the same numeric ID, 300, as
        // a terminal ID) must be entirely independent of the tenant-scope
        // counter above.
        $terminalResult = $service->checkTerminal(300);
        $this->assertTrue($terminalResult['allowed'], 'terminal scope must not be affected by the tenant scope hitting its limit');
        $this->assertSame(1, $terminalResult['count']);
    }

    public function test_global_counter_is_independent_from_tenant_and_terminal_counters(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        // Exhaust the global limit (5).
        for ($i = 0; $i < 5; $i++) {
            $service->checkGlobal();
        }
        $rejectedGlobal = $service->checkGlobal();
        $this->assertFalse($rejectedGlobal['allowed'], 'global counter must be exhausted after 6 increments against a limit of 5');

        // T044 does not compose scopes into one verdict: a tenant-scope
        // check must still be independently evaluated and allowed, even
        // though the global counter is currently exhausted.
        $tenantResult = $service->checkTenant(999);
        $this->assertTrue($tenantResult['allowed'], 'exhausting the global counter must not itself flip a tenant-scope check to rejected');
    }

    public function test_retry_after_seconds_and_reset_at_are_derived_from_the_same_window_reset(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:30')); // 30s into a 60s window

        $service = new IngestionFairnessService;
        $result = $service->checkTenant(1);

        $resetAt = Carbon::parse($result['reset_at']);
        $expectedRetryAfter = $resetAt->timestamp - Carbon::now()->timestamp;

        $this->assertSame(max(1, $expectedRetryAfter), $result['retry_after_seconds']);
        $this->assertGreaterThan(Carbon::now()->timestamp, $resetAt->timestamp, 'reset_at must be in the future relative to now');
    }

    public function test_redis_connection_failure_fails_open(): void
    {
        $this->fakeBrokenFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $this->assertTrue($service->checkGlobal()['allowed']);
        $this->assertTrue($service->checkTenant(1)['allowed']);
        $this->assertTrue($service->checkTerminal(1)['allowed']);
    }

    /**
     * Replaces the old "INCR ok, EXPIRE throws → misleading count" gap
     * (review finding F2): that scenario no longer exists once INCR+EXPIRE
     * are one atomic EVAL call, so instead this proves the single failure
     * mode that remains — the whole script call throwing — degrades
     * cleanly to a well-formed, fail-open result with count=0, and never
     * to a state where a real increment happened but was misreported.
     */
    public function test_eval_script_failure_fails_open_with_a_well_formed_result(): void
    {
        $this->fakeFairnessRedisWithFailingEval();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $result = $service->checkTenant(7);

        $this->assertSame(
            ['allowed', 'scope', 'limit', 'count', 'retry_after_seconds', 'reset_at'],
            array_keys($result)
        );
        $this->assertTrue($result['allowed']);
        $this->assertSame('tenant', $result['scope']);
        $this->assertSame(3, $result['limit']);
        $this->assertSame(0, $result['count']);
        $this->assertIsInt($result['retry_after_seconds']);
        $this->assertGreaterThanOrEqual(1, $result['retry_after_seconds']);
        $this->assertIsString($result['reset_at']);
    }

    public function test_sequential_calls_within_the_same_window_return_the_real_incrementing_count(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $first = $service->checkGlobal();
        $second = $service->checkGlobal();
        $third = $service->checkGlobal();

        $this->assertSame(1, $first['count']);
        $this->assertSame(2, $second['count']);
        $this->assertSame(3, $third['count']);
    }

    public function test_expire_is_called_only_once_when_the_window_counter_is_first_created(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $service->checkTenant(42);
        $service->checkTenant(42);
        $service->checkTenant(42);

        $key = 'fairness:tenant:42:'.intdiv(Carbon::now()->timestamp, 60);

        $this->assertSame(
            1,
            $this->fakeFairnessExpireCallCounts[$key] ?? 0,
            'EXPIRE must be invoked exactly once, only on the call that created the window key'
        );
        $this->assertSame(3, $this->fakeFairnessRedisStore[$key] ?? 0);
    }

    /**
     * Review finding F3: the old fake only recorded that expire-equivalent
     * behavior was invoked, never the actual TTL/seconds value it was
     * invoked with. This asserts the real configured window_seconds (60,
     * per setUp()) is the exact value the atomic script applies on
     * creation — not merely "some" TTL.
     */
    public function test_expire_ttl_equals_the_configured_window_seconds_exactly(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $service->checkTenant(55);

        $key = 'fairness:tenant:55:'.intdiv(Carbon::now()->timestamp, 60);

        $this->assertSame(
            60,
            $this->fakeFairnessExpireSecondsSeen[$key] ?? null,
            'the TTL applied on creation must equal the configured window_seconds exactly'
        );
    }
}
