<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use App\Support\TenantBreakerObserver;

class TenantBreakerObserverTest extends TestCase
{
    public function test_threshold_not_triggered_before_min_requests(): void
    {
        config()->set('tsms.tenant_breaker.observation.enabled', true);
        config()->set('tsms.tenant_breaker.observation.min_requests', 10);
        config()->set('tsms.tenant_breaker.observation.failure_ratio_threshold', 0.5);
        config()->set('tsms.tenant_breaker.observation.time_window_minutes', 5);

        $observer = new TenantBreakerObserver();
        $tenantId = 1234;

        for ($i=0; $i<5; $i++) { // below min_requests
            $observer->recordAttempt($tenantId);
            $observer->recordRetryableFailure($tenantId); // 100% failure but insufficient sample
        }

        $eval = $observer->evaluate($tenantId);
        $this->assertNotNull($eval);
        $this->assertFalse($eval['eligible']);
        $this->assertEquals(5, $eval['attempts']);
        $this->assertEquals(5, $eval['failures']);
        $this->assertEquals(1.0, $eval['failure_ratio']);
    }

    public function test_threshold_triggered_after_min_requests(): void
    {
        config()->set('tsms.tenant_breaker.observation.enabled', true);
        config()->set('tsms.tenant_breaker.observation.min_requests', 10);
        config()->set('tsms.tenant_breaker.observation.failure_ratio_threshold', 0.5);
        config()->set('tsms.tenant_breaker.observation.time_window_minutes', 5);

        $observer = new TenantBreakerObserver();
        $tenantId = 5678;

        for ($i=0; $i<10; $i++) {
            $observer->recordAttempt($tenantId);
        }
        // 6 failures gives ratio 0.6 > 0.5
        for ($i=0; $i<6; $i++) {
            $observer->recordRetryableFailure($tenantId);
        }

        $eval = $observer->evaluate($tenantId);
        $this->assertTrue($eval['eligible']);
        $this->assertTrue($eval['over_threshold']);
        $this->assertEquals(10, $eval['attempts']);
        $this->assertEquals(6, $eval['failures']);
        $this->assertEquals(0.6, $eval['failure_ratio']);
    }
}
