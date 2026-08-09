<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\TenantFairnessOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\FakesTenantThrottleRedis;

/**
 * WU5: unit tests for App\Services\TenantFairnessOverrideService — the
 * Redis-backed, TTL-bounded, incident-response tenant override store
 * consumed by IngestionFairnessService::checkTenantOverride() (see
 * tests/Unit/Services/IngestionFairnessOverrideTest.php for that
 * integration point, and tests/Feature/TenantThrottleOverrideMiddlewareTest.php
 * for the full HTTP-level behavior).
 *
 * Redis is faked via tests/Traits/FakesTenantThrottleRedis, mirroring the
 * established Mockery-double-over-Redis::connection() pattern used
 * elsewhere in this codebase (FakesIngestionFairnessRedis,
 * FakesSkewRankingRedis).
 */
class TenantFairnessOverrideServiceTest extends TestCase
{
    use FakesTenantThrottleRedis;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.tenant_throttle.redis_connection', 'default');
        config()->set('tsms.tenant_throttle.key_prefix', 'fairness:override:');
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 14400);

        $this->resetFakeTenantThrottleRedisStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Set + read-back, for each mode
    // ------------------------------------------------------------------

    public function test_set_blocked_override_is_read_back_correctly_via_resolve(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $setResult = $service->set($tenant->id, 'blocked', null, 120, 'live incident drill', 'ops-oncall');

        $this->assertFalse($setResult['replaced'], 'first set for a fresh tenant must report replaced=false (created)');
        $this->assertSame('blocked', $setResult['mode']);
        $this->assertNull($setResult['limit']);
        $this->assertSame(120, $setResult['ttl_seconds']);

        $resolved = $service->resolve($tenant->id);

        $this->assertSame('blocked', $resolved['mode']);
        $this->assertSame('override', $resolved['source']);
        $this->assertNull($resolved['limit']);
        $this->assertSame('live incident drill', $resolved['reason']);
        $this->assertSame('ops-oncall', $resolved['operator']);
        $this->assertSame(120, $resolved['retry_after_seconds']);
    }

    public function test_set_reduced_limit_override_is_read_back_correctly_via_resolve(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $service->set($tenant->id, 'reduced_limit', 5, 300, 'suspected runaway terminal', 'ops-oncall');
        $resolved = $service->resolve($tenant->id);

        $this->assertSame('reduced_limit', $resolved['mode']);
        $this->assertSame(5, $resolved['limit']);
        $this->assertSame(300, $resolved['retry_after_seconds']);
    }

    public function test_set_inherit_override_is_read_back_correctly_via_resolve(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $service->set($tenant->id, 'inherit', null, 60, 'ending drill early, keeping an audit record', 'ops-oncall');
        $resolved = $service->resolve($tenant->id);

        $this->assertSame('inherit', $resolved['mode']);
        $this->assertSame('override', $resolved['source'], "an explicit inherit override is still a real, live override record — 'source' distinguishes this from a merely-absent key");
        $this->assertNull($resolved['limit']);
    }

    public function test_replacing_an_existing_override_reports_replaced_true(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $service->set($tenant->id, 'blocked', null, 60, 'first reason', 'op-a');
        $second = $service->set($tenant->id, 'reduced_limit', 10, 60, 'second reason', 'op-b');

        $this->assertTrue($second['replaced']);
        $resolved = $service->resolve($tenant->id);
        $this->assertSame('reduced_limit', $resolved['mode'], 'the replacement must win, not the original');
        $this->assertSame(10, $resolved['limit']);
    }

    // ------------------------------------------------------------------
    // Clear idempotency
    // ------------------------------------------------------------------

    public function test_clearing_a_tenant_with_no_existing_override_is_a_no_op_success(): void
    {
        $this->fakeTenantThrottleRedis();

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $result = $service->clear($tenant->id, 'ops-oncall');

        $this->assertFalse($result['existed'], 'no override existed before this clear call');
        $this->assertSame($tenant->id, $result['tenant_id']);

        // Idempotent: calling it again is still a success, still existed=false.
        $result2 = $service->clear($tenant->id, 'ops-oncall');
        $this->assertFalse($result2['existed']);
    }

    public function test_clearing_an_existing_override_reports_existed_true_and_resolves_to_inherit_after(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $service->set($tenant->id, 'blocked', null, 300, 'drill', 'ops-oncall');

        $clearResult = $service->clear($tenant->id, 'ops-oncall');
        $this->assertTrue($clearResult['existed']);

        $resolved = $service->resolve($tenant->id);
        $this->assertSame('inherit', $resolved['mode']);
        $this->assertSame('absent', $resolved['source']);
    }

    public function test_clear_requires_a_non_empty_operator(): void
    {
        $this->fakeTenantThrottleRedis();

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $service->clear($tenant->id, '');
    }

    // ------------------------------------------------------------------
    // Max-TTL cap enforcement
    // ------------------------------------------------------------------

    public function test_ttl_exceeding_the_configured_maximum_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 3600);

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds the configured maximum/');

        $service->set($tenant->id, 'blocked', null, 3601, 'too long', 'ops-oncall');
    }

    public function test_ttl_exactly_at_the_configured_maximum_is_accepted(): void
    {
        $this->fakeTenantThrottleRedis();
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 3600);

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $result = $service->set($tenant->id, 'blocked', null, 3600, 'exactly at cap', 'ops-oncall');
        $this->assertSame(3600, $result['ttl_seconds']);
    }

    // ------------------------------------------------------------------
    // Validation: mode, limit, reason, operator, tenant existence
    // ------------------------------------------------------------------

    public function test_setting_an_override_for_a_nonexistent_tenant_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();

        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $service->set(999999999, 'blocked', null, 60, 'reason', 'ops-oncall');
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $service->set($tenant->id, 'throttled', null, 60, 'reason', 'ops-oncall');
    }

    public function test_reduced_limit_mode_without_a_positive_limit_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $service->set($tenant->id, 'reduced_limit', null, 60, 'reason', 'ops-oncall');
    }

    public function test_empty_reason_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $service->set($tenant->id, 'blocked', null, 60, '   ', 'ops-oncall');
    }

    public function test_empty_operator_is_rejected(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        $this->expectException(InvalidArgumentException::class);
        $service->set($tenant->id, 'blocked', null, 60, 'reason', '');
    }

    // ------------------------------------------------------------------
    // Fail-open primacy (Architecture Invariant 1) — the most important
    // behavior this store must guarantee.
    // ------------------------------------------------------------------

    public function test_total_redis_outage_resolves_to_inherit(): void
    {
        $this->fakeBrokenTenantThrottleRedis();

        $service = new TenantFairnessOverrideService;
        $resolved = $service->resolve(12345);

        $this->assertSame('inherit', $resolved['mode']);
        $this->assertSame('redis_error', $resolved['source']);
        $this->assertNull($resolved['limit']);
        $this->assertNull($resolved['retry_after_seconds']);
    }

    /**
     * The single most important test in this file: a Redis failure while
     * READING an override must resolve to inherit even when a genuine
     * `blocked` override was set moments earlier and is logically still
     * "in the store" — proving the fail-open path is exercised on the read
     * failure itself, not merely on a legitimately-absent key. A store that
     * only fails open for the (easy, less interesting) "nothing was ever
     * set" case would NOT satisfy Architecture Invariant 1.
     */
    public function test_redis_failure_reading_an_override_resolves_to_inherit_never_blocked_even_if_a_blocked_override_was_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $this->fakeTenantThrottleRedisWithFailingReads();

        $tenant = Tenant::factory()->create();
        $service = new TenantFairnessOverrideService;

        // Writes still succeed (this fake's eval() works normally) — a
        // genuine blocked override is set.
        $service->set($tenant->id, 'blocked', null, 300, 'incident in progress', 'ops-oncall');

        // But reading it back fails (this fake's get()/ttl() always throw).
        $resolved = $service->resolve($tenant->id);

        $this->assertSame('inherit', $resolved['mode'], 'a Redis read failure must NEVER resolve to blocked, even with a real blocked override on record');
        $this->assertSame('redis_error', $resolved['source']);
        $this->assertNull($resolved['limit']);
        $this->assertNull($resolved['retry_after_seconds']);
    }

    public function test_malformed_stored_payload_resolves_to_inherit(): void
    {
        $this->fakeTenantThrottleRedis();

        $tenantId = 555;
        $key = config('tsms.tenant_throttle.key_prefix').$tenantId;
        $this->seedFakeThrottleRaw($key, 'not valid json {{{', 300);

        $service = new TenantFairnessOverrideService;
        $resolved = $service->resolve($tenantId);

        $this->assertSame('inherit', $resolved['mode']);
        $this->assertSame('redis_error', $resolved['source']);
    }

    public function test_missing_override_resolves_to_inherit_with_absent_source(): void
    {
        $this->fakeTenantThrottleRedis();

        $service = new TenantFairnessOverrideService;
        $resolved = $service->resolve(777);

        $this->assertSame('inherit', $resolved['mode']);
        $this->assertSame('absent', $resolved['source']);
        $this->assertNull($resolved['reason']);
        $this->assertNull($resolved['operator']);
    }
}
