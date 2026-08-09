<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use App\Services\TenantFairnessOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesIngestionFairnessRedis;
use Tests\Traits\FakesTenantThrottleRedis;

/**
 * WU5: HTTP-level tests proving App\Http\Middleware\IngestionFairnessMiddleware
 * correctly wires in App\Services\IngestionFairnessService::checkTenantOverride()
 * BEFORE its existing global-limit decision.
 *
 * Deliberately a separate file from tests/Feature/IngestionFairnessMiddlewareTest.php
 * (T045, a prior-WU baseline suite that must remain untouched and 100%
 * clean) — this file only adds new scenarios, never modifies the existing
 * ones.
 *
 * The fairness base-check fake and the tenant-throttle override fake are
 * registered against two DIFFERENT Redis connection names (default vs.
 * tenant_throttle_test), for the same reason IngestionFairnessMiddlewareTest's
 * own circuit-breaker-ordering test does: two independent Mockery doubles
 * registered for the exact same connection name are ambiguous.
 */
class TenantThrottleOverrideMiddlewareTest extends TestCase
{
    use FakesIngestionFairnessRedis;
    use FakesTenantThrottleRedis;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.intake.backpressure.enabled', false);
        config()->set('tsms.circuit_breaker.enabled', false);

        config()->set('tsms.fairness.redis_connection', 'default');
        config()->set('tsms.fairness.key_prefix', 'fairness:');
        config()->set('tsms.fairness.window_seconds', 60);
        config()->set('tsms.fairness.global.limit', 1000);
        config()->set('tsms.fairness.tenant.limit', 10);
        config()->set('tsms.fairness.terminal.limit', 10);

        config()->set('tsms.tenant_throttle.redis_connection', 'tenant_throttle_test');
        config()->set('tsms.tenant_throttle.key_prefix', 'fairness:override:');
        config()->set('tsms.tenant_throttle.max_ttl_seconds', 14400);

        $this->resetFakeFairnessRedisStore();
        $this->resetFakeTenantThrottleRedisStore();

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // blocked: immediate 429 with Retry-After == remaining TTL
    // ------------------------------------------------------------------

    public function test_blocked_override_rejects_immediately_with_retry_after_equal_to_remaining_ttl(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();
        $this->fakeTenantThrottleRedis('tenant_throttle_test');

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $overrideService = new TenantFairnessOverrideService;
        $overrideService->set($tenant->id, 'blocked', null, 120, 'live incident drill', 'ops-oncall');

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'TENANT_THROTTLE_BLOCKED')
            ->assertJsonPath('scope', 'tenant_override');

        $this->assertSame('120', $response->headers->get('Retry-After'));
        $this->assertSame(120, $response->json('retry_after_seconds'));

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('transaction_intake', ['tenant_id' => $tenant->id]);
    }

    /**
     * Proves the override check runs BEFORE checkGlobal() — a blocked
     * tenant's rejected request must never consume the global fixed-window
     * budget shared by every other tenant.
     */
    public function test_blocked_override_short_circuits_before_the_global_fairness_check_runs(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();
        $this->fakeTenantThrottleRedis('tenant_throttle_test');

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $overrideService = new TenantFairnessOverrideService;
        $overrideService->set($tenant->id, 'blocked', null, 120, 'live incident drill', 'ops-oncall');

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(429);

        $globalKey = config('tsms.fairness.key_prefix').'global:0:'.intdiv(Carbon::now()->timestamp, (int) config('tsms.fairness.window_seconds'));
        $this->assertSame(0, $this->fakeFairnessRedisCount($globalKey), 'a blocked-override rejection must never increment the shared global fairness counter');
    }

    // ------------------------------------------------------------------
    // reduced_limit: applies only to the overridden tenant
    // ------------------------------------------------------------------

    public function test_reduced_limit_override_throttles_only_the_overridden_tenant_at_a_lower_limit(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();
        $this->fakeTenantThrottleRedis('tenant_throttle_test');

        [$hotTenant, $hotTerminal] = $this->seedTenantAndTerminal();
        [$normalTenant, $normalTerminal] = $this->seedTenantAndTerminal();

        $overrideService = new TenantFairnessOverrideService;
        $overrideService->set($hotTenant->id, 'reduced_limit', 1, 300, 'suspected runaway terminal', 'ops-oncall');

        // First request for the overridden tenant consumes its reduced
        // budget of 1 and succeeds.
        $firstPayload = $this->officialPayload($hotTenant->id, $hotTerminal->id, (string) Str::uuid(), $hotTerminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $firstPayload, $this->headersFor($hotTerminal))
            ->assertStatus(202);

        $this->app['auth']->forgetGuards();

        // Second request for the SAME overridden tenant exceeds its
        // reduced limit of 1 (well below the configured default of 10)
        // and is rejected under the ORGANIC fairness path (not blocked).
        $secondPayload = $this->officialPayload($hotTenant->id, $hotTerminal->id, (string) Str::uuid(), $hotTerminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $secondPayload, $this->headersFor($hotTerminal))
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'FAIRNESS_LIMIT_EXCEEDED')
            ->assertJsonPath('scope', 'tenant')
            ->assertJsonPath('limit', 1);

        $this->app['auth']->forgetGuards();

        // A different tenant, with no override, still gets the full
        // configured default limit (10) and is completely unaffected by
        // the hot tenant's override.
        for ($i = 0; $i < 3; $i++) {
            $normalPayload = $this->officialPayload($normalTenant->id, $normalTerminal->id, (string) Str::uuid(), $normalTerminal->serial_number);
            $this->postJson('/api/v1/transactions/official', $normalPayload, $this->headersFor($normalTerminal))
                ->assertStatus(202);
            $this->app['auth']->forgetGuards();
        }
    }

    // ------------------------------------------------------------------
    // Fail-open at the HTTP level: a Redis read failure on the override
    // store must never turn into a blocked/429 response.
    // ------------------------------------------------------------------

    public function test_redis_failure_reading_the_override_fails_open_and_the_request_proceeds_normally(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();
        $this->fakeTenantThrottleRedisWithFailingReads('tenant_throttle_test');

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // A genuine blocked override is on record (write succeeds via this
        // fake's working eval()); the read path is what's broken.
        $overrideService = new TenantFairnessOverrideService;
        $overrideService->set($tenant->id, 'blocked', null, 300, 'incident drill', 'ops-oncall');

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame('TENANT_THROTTLE_BLOCKED', $response->json('error_code'));
        $response->assertStatus(202)->assertJsonPath('success', true);
    }

    // ------------------------------------------------------------------
    // Helpers (mirrors tests/Feature/IngestionFairnessMiddlewareTest.php's
    // own private helpers, duplicated rather than shared so that file's
    // baseline stability is never at risk from this one's edits)
    // ------------------------------------------------------------------

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function headersFor(PosTerminal $terminal): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
        ];
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'FN-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'adjustments' => $this->adjustments(),
            'taxes' => $this->taxes(),
        ];
        $transaction['payload_checksum'] = $service->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
    }

    private function adjustments(): array
    {
        return [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
    }

    private function taxes(): array
    {
        return [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];
    }
}
