<?php

namespace Tests\Unit;

use App\Services\IngestionQueueRouter;
use Tests\TestCase;

class IngestionQueueRouterTest extends TestCase
{
    public function test_intake_and_processing_shards_are_deterministic_for_tenant(): void
    {
        config()->set('tsms.intake.shard_count', 8);
        config()->set('tsms.processing.shard_count', 8);
        config()->set('tsms.rollout.pilot_tenants', []);

        $router = app(IngestionQueueRouter::class);
        $expectedShard = crc32('42') % 8;

        $this->assertSame("transaction-intake:s{$expectedShard}", $router->intakeQueueForTenant(42));
        $this->assertSame("transaction-processing:s{$expectedShard}", $router->processingQueueForTenant(42));
        $this->assertSame($router->intakeQueueForTenant(42), $router->intakeQueueForTenant('42'));
    }

    public function test_pilot_tenants_route_to_configured_vip_intake_lane(): void
    {
        config()->set('tsms.rollout.pilot_tenants', ['42']);
        config()->set('tsms.intake.vip_shard', 'vip');

        $this->assertSame('transaction-intake:s-vip', app(IngestionQueueRouter::class)->intakeQueueForTenant(42));
    }
}
