<?php

namespace Tests\Unit;

use App\Services\IngestionFairnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesIngestionFairnessRedis;
use Tests\Traits\FakesTenantThrottleRedis;

/**
 * WU5: unit tests for the integration point between
 * App\Services\IngestionFairnessService and
 * App\Services\TenantFairnessOverrideService —
 * IngestionFairnessService::checkTenantOverride() (new) and
 * IngestionFairnessService::checkTenant()'s new optional $limitOverride
 * parameter.
 *
 * Deliberately separate from tests/Unit/IngestionFairnessServiceTest.php
 * (T044/T038, which predates WU5 and must remain untouched) and from
 * tests/Unit/TenantFairnessOverrideServiceTest.php (which exercises
 * TenantFairnessOverrideService in isolation, with no IngestionFairnessService
 * involved at all). This file is the one place that proves the two services
 * compose correctly.
 *
 * `new IngestionFairnessService()` (no constructor args) still works after
 * WU5 — the constructor's $overrideService parameter defaults to null and
 * is lazily resolved from the container only when checkTenantOverride() is
 * actually called, so this file's `new IngestionFairnessService()` calls
 * pick up a real TenantFairnessOverrideService whose own Redis calls are
 * faked via FakesTenantThrottleRedis below (Redis::shouldReceive is a
 * global facade fake, independent of which object instance calls it).
 */
class IngestionFairnessOverrideIntegrationTest extends TestCase
{
    use FakesIngestionFairnessRedis;
    use FakesTenantThrottleRedis;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.fairness.redis_connection', 'default');
        config()->set('tsms.fairness.key_prefix', 'fairness:');
        config()->set('tsms.fairness.window_seconds', 60);
        config()->set('tsms.fairness.global.limit', 1000);
        config()->set('tsms.fairness.tenant.limit', 10);
        config()->set('tsms.fairness.terminal.limit', 10);

        config()->set('tsms.tenant_throttle.redis_connection', 'default');
        config()->set('tsms.tenant_throttle.key_prefix', 'fairness:override:');
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 14400);

        $this->resetFakeFairnessRedisStore();
        $this->resetFakeTenantThrottleRedisStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_check_tenant_override_reports_inherit_when_no_override_exists(): void
    {
        $this->fakeTenantThrottleRedis();

        $service = new IngestionFairnessService;
        $result = $service->checkTenantOverride(4001);

        $this->assertSame('inherit', $result['mode']);
        $this->assertTrue($result['allowed']);
        $this->assertSame('tenant_override', $result['scope']);
        $this->assertNull($result['limit']);
    }

    public function test_check_tenant_override_reports_blocked_with_remaining_ttl_as_retry_after(): void
    {
        $this->fakeTenantThrottleRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $tenantId = 4002;
        \App\Models\Tenant::factory()->create(['id' => $tenantId]);

        $overrideService = new \App\Services\TenantFairnessOverrideService;
        $overrideService->set($tenantId, 'blocked', null, 90, 'incident drill', 'ops-oncall');

        $service = new IngestionFairnessService;
        $result = $service->checkTenantOverride($tenantId);

        $this->assertSame('blocked', $result['mode']);
        $this->assertFalse($result['allowed'], 'a blocked override must resolve to allowed=false so the caller short-circuits');
        $this->assertSame('tenant_override', $result['scope']);
        $this->assertSame(90, $result['retry_after_seconds'], 'retry_after_seconds must equal the override\'s remaining TTL');
    }

    public function test_check_tenant_with_limit_override_applies_the_reduced_limit_instead_of_the_configured_default(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $tenantId = 4003;

        // Configured default tenant limit is 10 (setUp), but a reduced
        // limit of 2 is passed directly here (this test exercises
        // checkTenant()'s new parameter in isolation from the override
        // store, mirroring how the middleware would use it after reading
        // checkTenantOverride()'s 'limit').
        $first = $service->checkTenant($tenantId, 2);
        $this->assertTrue($first['allowed']);
        $this->assertSame(2, $first['limit'], 'the reduced limit must be reported, not the configured default of 10');

        $second = $service->checkTenant($tenantId, 2);
        $this->assertTrue($second['allowed'], 'count 2 is still at the reduced limit');

        $third = $service->checkTenant($tenantId, 2);
        $this->assertFalse($third['allowed'], 'the third request must be rejected under the reduced limit of 2, well below the configured default of 10');
        $this->assertSame(2, $third['limit']);
    }

    public function test_check_tenant_without_a_limit_override_still_uses_the_configured_default(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $result = $service->checkTenant(4004);
        $this->assertSame(10, $result['limit'], 'omitting the override parameter must preserve existing behavior exactly');
    }

    public function test_check_tenant_override_fails_open_to_inherit_never_blocked_on_redis_read_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $this->fakeTenantThrottleRedisWithFailingReads();

        $tenantId = 4005;
        \App\Models\Tenant::factory()->create(['id' => $tenantId]);

        $overrideService = new \App\Services\TenantFairnessOverrideService;
        $overrideService->set($tenantId, 'blocked', null, 300, 'incident drill', 'ops-oncall');

        $service = new IngestionFairnessService;
        $result = $service->checkTenantOverride($tenantId);

        $this->assertSame('inherit', $result['mode'], 'IngestionFairnessService::checkTenantOverride() must fail open to inherit on a Redis read failure, even with a real blocked override on record');
        $this->assertTrue($result['allowed']);
    }
}
