<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicDebugRoutesTest extends TestCase
{
    public function test_public_debug_and_demo_routes_are_not_registered(): void
    {
        $this->assertRouteIsNotPublic($this->getJson('/api/v1/retry-history/debug')->getStatusCode());
        $this->assertRouteIsNotPublic($this->getJson('/api/v1/retry-history/emergency-data')->getStatusCode());
        $this->assertRouteIsNotPublic($this->postJson('/api/v1/retry-history/seed')->getStatusCode());
        $this->assertRouteIsNotPublic($this->postJson('/api/v1/retry-history/force-seed')->getStatusCode());
        $this->assertRouteIsNotPublic($this->getJson('/api/v1/transactions/1/details')->getStatusCode());
        $this->assertRouteIsNotPublic($this->getJson('/api/v1/transaction-id-exists?id=TXN-1')->getStatusCode());
        $this->assertRouteIsNotPublic($this->getJson('/api/web/dashboard/logs')->getStatusCode());
        $this->assertRouteIsNotPublic($this->postJson('/api/transactions/bulk')->getStatusCode());
    }

    private function assertRouteIsNotPublic(int $status): void
    {
        $this->assertContains($status, [401, 404, 405]);
    }
}
