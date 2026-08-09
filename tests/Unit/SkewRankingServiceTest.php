<?php

namespace Tests\Unit;

use App\Services\SkewRankingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesSkewRankingRedis;

/**
 * Unit tests for App\Services\SkewRankingService (WU4, T053 remainder) — the
 * bounded, time-windowed tenant/terminal "top-N talkers" ranking required by
 * Architecture Invariant 5 (bounded cardinality). Uses the fake in-memory
 * Redis double from FakesSkewRankingRedis since CI provisions no real Redis
 * service (mirrors MetricsDistributionTest's use of
 * FakesMetricsDistributionRedis).
 */
class SkewRankingServiceTest extends TestCase
{
    use FakesSkewRankingRedis;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.metrics.skew.redis_connection', 'default');
        config()->set('tsms.metrics.skew.key_prefix', 'metrics:skew:');
        config()->set('tsms.metrics.skew.window_seconds', 300);
        config()->set('tsms.metrics.skew.member_cap', 5);
        config()->set('tsms.metrics.skew.ttl_seconds', 600);
        config()->set('tsms.metrics.skew.max_top_n', 100);

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $this->fakeSkewRankingRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_recording_increments_a_members_score(): void
    {
        $service = new SkewRankingService;

        $service->recordTenant(101);
        $service->recordTenant(101);
        $service->recordTenant(102);

        $top = $service->topTenants(10);

        $this->assertCount(2, $top);
        $this->assertSame('101', $top[0]['member']);
        $this->assertSame(2.0, $top[0]['count']);
        $this->assertSame('102', $top[1]['member']);
        $this->assertSame(1.0, $top[1]['count']);
    }

    public function test_tenant_and_terminal_rankings_are_independent(): void
    {
        $service = new SkewRankingService;

        $service->recordTenant(101);
        $service->recordTerminal(9001);
        $service->recordTerminal(9001);

        $this->assertSame(1, $service->tenantMemberCount());
        $this->assertSame(1, $service->terminalMemberCount());
        $this->assertSame(2.0, $service->topTerminals(10)[0]['count']);
    }

    /**
     * Architecture Invariant 5 (bounded cardinality): inserting more
     * distinct members than member_cap (5, per setUp()) must never grow the
     * underlying ZSET past the cap — the lowest-ranked member is evicted on
     * each insert past the cap instead.
     */
    public function test_member_cap_bounds_the_underlying_zset_and_evicts_the_lowest_ranked_member(): void
    {
        $service = new SkewRankingService;

        // Seed 5 tenants (at the cap) with distinct, ascending counts.
        foreach ([100, 101, 102, 103, 104] as $i => $tenantId) {
            for ($n = 0; $n <= $i; $n++) {
                $service->recordTenant($tenantId);
            }
        }
        $this->assertSame(5, $service->tenantMemberCount());

        // A 6th, brand-new tenant must push the cap and evict the
        // lowest-ranked existing member (tenant 100, count 1) rather than
        // growing the set to 6.
        $service->recordTenant(200);

        $this->assertSame(5, $service->tenantMemberCount(), 'member cap must never be exceeded');

        $members = array_column($service->topTenants(10), 'member');
        $this->assertNotContains('100', $members, 'the lowest-ranked member must be evicted, not the newcomer');
        $this->assertContains('200', $members);
    }

    public function test_top_n_read_is_bounded_to_max_top_n_even_when_a_larger_limit_is_requested(): void
    {
        config()->set('tsms.metrics.skew.max_top_n', 2);

        $service = new SkewRankingService;
        $service->recordTenant(1);
        $service->recordTenant(2);
        $service->recordTenant(3);
        $service->recordTenant(4);

        $top = $service->topTenants(100); // requests far more than max_top_n

        $this->assertCount(2, $top, 'bounded top-N reads only — must never exceed max_top_n regardless of what is requested');
    }

    public function test_top_n_returns_highest_count_members_first(): void
    {
        $service = new SkewRankingService;

        $service->recordTenant(1, 3);
        $service->recordTenant(2, 10);
        $service->recordTenant(3, 1);

        $top = $service->topTenants(10);

        $this->assertSame(['2', '1', '3'], array_column($top, 'member'));
        $this->assertSame([10.0, 3.0, 1.0], array_column($top, 'count'));
    }

    /** Fail-safe: a Redis failure while recording must never throw and must never affect ingestion. */
    public function test_recording_does_not_throw_when_redis_fails(): void
    {
        \Illuminate\Support\Facades\Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new \RuntimeException('Redis down'));

        $service = new SkewRankingService;

        // Must not throw.
        $service->recordTenant(101);
        $service->recordTerminal(9001);

        $this->assertTrue(true);
    }

    /** Fail-safe: a Redis failure while reading top-N must never throw, only return an empty result. */
    public function test_top_n_read_returns_empty_when_redis_fails(): void
    {
        \Illuminate\Support\Facades\Redis::shouldReceive('connection')
            ->with('default')
            ->andThrow(new \RuntimeException('Redis down'));

        $service = new SkewRankingService;

        $this->assertSame([], $service->topTenants(10));
    }
}
