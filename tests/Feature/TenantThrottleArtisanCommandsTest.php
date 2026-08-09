<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesTenantThrottleRedis;

/**
 * WU5: end-to-end tests for the three new Artisan commands
 * (`ingestion:tenant-throttle:set`, `ingestion:tenant-throttle:status`,
 * `ingestion:tenant-throttle:clear`), backed by
 * App\Services\TenantFairnessOverrideService with Redis faked via
 * tests/Traits/FakesTenantThrottleRedis.
 */
class TenantThrottleArtisanCommandsTest extends TestCase
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

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_set_then_status_then_clear_then_status_end_to_end(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();

        \Artisan::call('ingestion:tenant-throttle:set', [
            '--tenant' => (string) $tenant->id,
            '--mode' => 'blocked',
            '--ttl' => '300',
            '--reason' => 'incident drill',
            '--operator' => 'ops-oncall',
        ]);
        $setOutput = \Artisan::output();
        $this->assertStringContainsString('Override created', $setOutput);
        $this->assertStringContainsString('blocked', $setOutput);

        \Artisan::call('ingestion:tenant-throttle:status', [
            '--tenant' => (string) $tenant->id,
        ]);
        $statusOutput = \Artisan::output();
        $this->assertStringContainsString('blocked', $statusOutput);
        $this->assertStringContainsString('ops-oncall', $statusOutput);
        $this->assertStringContainsString('incident drill', $statusOutput);

        $clearExit = \Artisan::call('ingestion:tenant-throttle:clear', [
            '--tenant' => (string) $tenant->id,
            '--operator' => 'ops-oncall',
        ]);
        $this->assertSame(0, $clearExit);
        $this->assertStringContainsString('Override cleared', \Artisan::output());

        \Artisan::call('ingestion:tenant-throttle:status', [
            '--tenant' => (string) $tenant->id,
        ]);
        $statusAfterClear = \Artisan::output();
        $this->assertStringContainsString('inherit', $statusAfterClear);
        $this->assertStringContainsString('No active override', $statusAfterClear);
    }

    public function test_set_with_reduced_limit_mode_requires_and_records_limit(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();

        $exit = \Artisan::call('ingestion:tenant-throttle:set', [
            '--tenant' => (string) $tenant->id,
            '--mode' => 'reduced_limit',
            '--limit' => '5',
            '--ttl' => '60',
            '--reason' => 'suspected runaway terminal',
            '--operator' => 'ops-oncall',
        ]);

        $this->assertSame(0, $exit);

        \Artisan::call('ingestion:tenant-throttle:status', ['--tenant' => (string) $tenant->id]);
        $output = \Artisan::output();
        $this->assertStringContainsString('reduced_limit', $output);
        $this->assertStringContainsString('5', $output);
    }

    public function test_clear_on_a_tenant_with_no_override_is_a_no_op_success(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();

        $exit = \Artisan::call('ingestion:tenant-throttle:clear', [
            '--tenant' => (string) $tenant->id,
            '--operator' => 'ops-oncall',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No override existed', \Artisan::output());
    }

    public function test_set_rejects_a_ttl_exceeding_the_configured_maximum(): void
    {
        $this->fakeTenantThrottleRedis();
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 3600);
        $tenant = Tenant::factory()->create();

        $exit = \Artisan::call('ingestion:tenant-throttle:set', [
            '--tenant' => (string) $tenant->id,
            '--mode' => 'blocked',
            '--ttl' => '999999',
            '--reason' => 'too long',
            '--operator' => 'ops-oncall',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('exceeds the configured maximum', \Artisan::output());
    }

    public function test_set_rejects_a_nonexistent_tenant(): void
    {
        $this->fakeTenantThrottleRedis();

        $exit = \Artisan::call('ingestion:tenant-throttle:set', [
            '--tenant' => '999999999',
            '--mode' => 'blocked',
            '--ttl' => '60',
            '--reason' => 'reason',
            '--operator' => 'ops-oncall',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not exist', \Artisan::output());
    }

    public function test_set_requires_operator_identity(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();

        $exit = \Artisan::call('ingestion:tenant-throttle:set', [
            '--tenant' => (string) $tenant->id,
            '--mode' => 'blocked',
            '--ttl' => '60',
            '--reason' => 'reason',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--operator is required', \Artisan::output());
    }

    public function test_clear_requires_operator_identity(): void
    {
        $this->fakeTenantThrottleRedis();
        $tenant = Tenant::factory()->create();

        $exit = \Artisan::call('ingestion:tenant-throttle:clear', [
            '--tenant' => (string) $tenant->id,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--operator is required', \Artisan::output());
    }

    public function test_status_on_a_tenant_with_no_override_shows_inherit(): void
    {
        $this->fakeTenantThrottleRedis();

        \Artisan::call('ingestion:tenant-throttle:status', ['--tenant' => '123456']);
        $output = \Artisan::output();

        $this->assertStringContainsString('inherit', $output);
        $this->assertStringContainsString('No active override', $output);
    }
}
