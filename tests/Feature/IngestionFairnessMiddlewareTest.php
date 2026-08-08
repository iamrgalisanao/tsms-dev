<?php

namespace Tests\Feature;

use App\Http\Middleware\IngestionFairnessMiddleware;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;
use Tests\Traits\FakesIngestionFairnessRedis;

/**
 * T045: HTTP-level tests for App\Http\Middleware\IngestionFairnessMiddleware,
 * wired onto `/api/v1/transactions/official` and `/api/v1/transactions/batch`
 * as the `ingestion.fairness` alias, positioned last in the ingestion
 * middleware chain (after ingestion.payload_size,
 * ingestion.backpressure:processing, circuit.breaker:transaction-intake).
 *
 * These are deliberately separate from tests/Feature/IngestionFairnessTest.php
 * (T038), which exercises App\Services\IngestionFairnessService directly
 * with no middleware/route involved. This file proves the middleware wiring,
 * identity resolution, and response contract at the real HTTP boundary.
 *
 * Backpressure and the circuit breaker are disabled by default in setUp() so
 * fairness behavior can be isolated, mirroring the same isolation pattern
 * IngestionCircuitBreakerTest.php and IngestionBackpressureTest.php already
 * use for their own respective concerns. The one exception is
 * test_fairness_only_runs_after_circuit_breaker_would_have_already_rejected(),
 * which deliberately re-enables the circuit breaker to prove middleware
 * ordering.
 */
class IngestionFairnessMiddlewareTest extends TestCase
{
    use FakesCircuitBreakerRedis;
    use FakesIngestionFairnessRedis;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetFakeFairnessRedisStore();

        config()->set('tsms.intake.backpressure.enabled', false);
        config()->set('tsms.circuit_breaker.enabled', false);

        config()->set('tsms.fairness.redis_connection', 'default');
        config()->set('tsms.fairness.key_prefix', 'fairness:');
        config()->set('tsms.fairness.window_seconds', 60);
        config()->set('tsms.fairness.global.limit', 1000);
        config()->set('tsms.fairness.tenant.limit', 3);
        config()->set('tsms.fairness.terminal.limit', 2);

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Enforcement on both routes
    // ------------------------------------------------------------------

    public function test_official_endpoint_enforces_fairness(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenant->id), $this->tenantLimit());

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FAIRNESS_LIMIT_EXCEEDED')
            ->assertJsonPath('scope', 'tenant');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('transaction_intake', [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_batch_endpoint_enforces_fairness(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenant->id), $this->tenantLimit());

        $payload = $this->batchStorePayload($terminal, 1);
        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FAIRNESS_LIMIT_EXCEEDED')
            ->assertJsonPath('scope', 'tenant');

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // Identity resolution: authenticated terminal wins over payload claims
    // ------------------------------------------------------------------

    public function test_authenticated_terminal_identity_takes_precedence_over_payload_identity(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenantA, $terminalA] = $this->seedTenantAndTerminal();
        [$tenantB] = $this->seedTenantAndTerminal();

        // Case 1: exhaust the AUTHENTICATED tenant's (A) bucket, but have the
        // payload claim a different, non-exhausted tenant (B). If the
        // middleware mistakenly trusted the payload's tenant_id, this
        // request would be allowed; since it must use the authenticated
        // identity, it must be rejected.
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenantA->id), $this->tenantLimit());

        $payload = $this->officialPayload($tenantB->id, $terminalA->id, (string) Str::uuid(), $terminalA->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminalA));

        $response->assertStatus(429)
            ->assertJsonPath('scope', 'tenant');

        // Case 2 (inverse): exhaust the PAYLOAD-claimed tenant's (B) bucket,
        // while the authenticated tenant (A) is fresh again. If the
        // middleware used the payload's identity, this would be rejected
        // with a fairness 429; since it must use the authenticated
        // identity, fairness must let it through instead. The payload's
        // tenant_id (B) still won't match terminal A's real tenant, so
        // downstream structural validation rejects it with 422 afterward —
        // that 422 (never a 429 FAIRNESS_LIMIT_EXCEEDED) is exactly the
        // proof that fairness itself did not block on tenant B's exhausted
        // bucket.
        $this->resetFakeFairnessRedisStore();
        $this->fakeFairnessRedis();
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenantB->id), $this->tenantLimit());

        $payload2 = $this->officialPayload($tenantB->id, $terminalA->id, (string) Str::uuid(), $terminalA->serial_number);
        $response2 = $this->postJson('/api/v1/transactions/official', $payload2, $this->headersFor($terminalA));

        $this->assertNotSame('FAIRNESS_LIMIT_EXCEEDED', $response2->json('error_code'));
        $response2->assertStatus(422)
            ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE');
    }

    // ------------------------------------------------------------------
    // Sibling isolation at the HTTP level
    // ------------------------------------------------------------------

    public function test_sibling_tenant_remains_unaffected_when_one_tenant_is_rejected(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$hotTenant, $hotTerminal] = $this->seedTenantAndTerminal();
        [$coldTenant, $coldTerminal] = $this->seedTenantAndTerminal();

        $this->seedFairnessCount($this->fairnessKey('tenant', $hotTenant->id), $this->tenantLimit());

        $hotPayload = $this->officialPayload($hotTenant->id, $hotTerminal->id, (string) Str::uuid(), $hotTerminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $hotPayload, $this->headersFor($hotTerminal))
            ->assertStatus(429)
            ->assertJsonPath('scope', 'tenant');

        // Laravel's Sanctum guard caches the resolved user on its guard
        // instance for the lifetime of the underlying AuthManager, which
        // persists across separate simulated postJson() calls within one
        // test method. Forgetting the resolved guards forces the next
        // request to re-resolve the authenticated PosTerminal from ITS OWN
        // Bearer token rather than reusing the previous request's terminal.
        $this->app['auth']->forgetGuards();

        $coldPayload = $this->officialPayload($coldTenant->id, $coldTerminal->id, (string) Str::uuid(), $coldTerminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $coldPayload, $this->headersFor($coldTerminal))
            ->assertStatus(202)
            ->assertJsonPath('success', true);
    }

    // ------------------------------------------------------------------
    // All three rejection scopes, end-to-end through HTTP
    // ------------------------------------------------------------------

    public function test_global_scope_rejection_end_to_end(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();
        config()->set('tsms.fairness.global.limit', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // First request consumes the single global slot and succeeds.
        $first = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $first, $this->headersFor($terminal))
            ->assertStatus(202);

        // Second request (different terminal/tenant, irrelevant to the
        // global scope) exceeds the exhausted global counter.
        [$otherTenant, $otherTerminal] = $this->seedTenantAndTerminal();
        $second = $this->officialPayload($otherTenant->id, $otherTerminal->id, (string) Str::uuid(), $otherTerminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $second, $this->headersFor($otherTerminal))
            ->assertStatus(429)
            ->assertJsonPath('scope', 'global');
    }

    public function test_tenant_scope_rejection_end_to_end(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenant->id), $this->tenantLimit());

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(429)
            ->assertJsonPath('scope', 'tenant');
    }

    public function test_terminal_scope_rejection_end_to_end(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->seedFairnessCount($this->fairnessKey('terminal', $terminal->id), $this->terminalLimit());

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(429)
            ->assertJsonPath('scope', 'terminal');
    }

    // ------------------------------------------------------------------
    // Retry-After header/body parity (T028b/T035-style assertion)
    // ------------------------------------------------------------------

    public function test_retry_after_header_and_json_body_match_on_rejection(): void
    {
        Queue::fake();
        $this->fakeFairnessRedis();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenant->id), $this->tenantLimit());

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(429);

        $bodyRetryAfter = $response->json('retry_after_seconds');
        $headerRetryAfter = $response->headers->get('Retry-After');

        $this->assertIsInt($bodyRetryAfter);
        $this->assertGreaterThanOrEqual(1, $bodyRetryAfter);
        $this->assertSame((string) $bodyRetryAfter, $headerRetryAfter);
    }

    // ------------------------------------------------------------------
    // Fail-open on Redis failure, at the HTTP level
    // ------------------------------------------------------------------

    public function test_redis_failure_fails_open_at_http_level(): void
    {
        Queue::fake();
        $this->fakeFairnessRedisWithFailingEval();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame('FAIRNESS_LIMIT_EXCEEDED', $response->json('error_code'));
        $response->assertStatus(202)->assertJsonPath('success', true);
    }

    // ------------------------------------------------------------------
    // Unresolved identity: no placeholder bucket is ever created
    // ------------------------------------------------------------------

    public function test_unresolved_identity_skips_tenant_and_terminal_checks_without_seeding_a_placeholder_bucket(): void
    {
        $this->fakeFairnessRedis();

        /** @var IngestionFairnessMiddleware $middleware */
        $middleware = app(IngestionFairnessMiddleware::class);

        // No authenticated user, no tenant_id/terminal_id in the request at
        // all — identity is fully unresolvable.
        $request = Request::create('/api/v1/transactions/official', 'POST', []);

        $downstreamCalled = false;
        $response = $middleware->handle($request, function ($req) use (&$downstreamCalled) {
            $downstreamCalled = true;

            return response()->json(['ok' => true]);
        });

        $this->assertTrue($downstreamCalled, 'fairness must let the request proceed when identity cannot be resolved');
        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame(
            0,
            $this->fakeFairnessRedisCount($this->fairnessKey('tenant', 0)),
            'no placeholder tenant bucket keyed at scope id 0 may be created for unresolved identity'
        );
        $this->assertSame(
            0,
            $this->fakeFairnessRedisCount($this->fairnessKey('terminal', 0)),
            'no placeholder terminal bucket keyed at scope id 0 may be created for unresolved identity'
        );
    }

    // ------------------------------------------------------------------
    // Middleware order: fairness only matters after backpressure/circuit
    // breaker would have already accepted the request
    // ------------------------------------------------------------------

    public function test_fairness_only_runs_after_circuit_breaker_would_have_already_rejected(): void
    {
        Queue::fake();

        // Both FakesIngestionFairnessRedis and FakesCircuitBreakerRedis
        // register a Mockery double against Redis::connection('default') by
        // default. Registering two independent doubles for the exact same
        // connection name is ambiguous — whichever one "wins" leaves the
        // other service calling methods its double never stubbed, and
        // App\Services\CircuitBreaker fails open on any such Redis error,
        // which would silently defeat this test's whole premise (the
        // breaker never actually opening). Give fairness its own distinct
        // connection name here so both fakes coexist cleanly.
        config()->set('tsms.fairness.redis_connection', 'fairness_mw_order_test');
        $this->fakeFairnessRedis('fairness_mw_order_test');

        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.redis_connection', 'default');
        config()->set('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:');
        config()->set('tsms.circuit_breaker.failure_threshold', 3);
        config()->set('tsms.circuit_breaker.reset_timeout_seconds', 60);
        config()->set('tsms.circuit_breaker.state_ttl_seconds', 3600);
        $this->fakeCircuitBreakerRedis();

        // This scenario reuses the SAME terminal for all 3 opening-failure
        // requests, so raise the terminal-scope limit well above 3 —
        // otherwise the terminal scope (not the tenant scope this test is
        // deliberately targeting) would trip first, on the 3rd request.
        config()->set('tsms.fairness.terminal.limit', 100);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $shouldFail = true;
        TransactionIntake::creating(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Simulated DB outage');
            }
        });

        // Drive exactly failure_threshold (3) infrastructure failures to
        // open the breaker. Fairness's tenant limit (3) is not yet
        // exceeded by these three requests (count reaches exactly the
        // limit, which is still allowed).
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503)
                ->assertJsonPath('error_code', 'INTAKE_PERSISTENCE_FAILED');
        }

        // Force fairness to ALSO already be over its limit for this same
        // tenant, so both conditions are simultaneously true for the next
        // request.
        $this->seedFairnessCount($this->fairnessKey('tenant', $tenant->id), $this->tenantLimit() + 10);

        $shouldFail = false;

        // The breaker is now open AND fairness is exhausted. If fairness
        // genuinely runs last (its handle() is only reached via
        // circuit.breaker's own $next() call), this request must surface
        // the breaker's OPEN rejection, not a fairness rejection.
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(503)
            ->assertJson([
                'error' => 'Service unavailable',
                'service' => 'transaction-intake',
                'message' => 'Circuit is open due to multiple failures',
            ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function tenantLimit(): int
    {
        return (int) config('tsms.fairness.tenant.limit');
    }

    private function terminalLimit(): int
    {
        return (int) config('tsms.fairness.terminal.limit');
    }

    private function windowBucket(): int
    {
        return intdiv(Carbon::now()->timestamp, (int) config('tsms.fairness.window_seconds'));
    }

    private function fairnessKey(string $scope, int $scopeId): string
    {
        return config('tsms.fairness.key_prefix')."{$scope}:{$scopeId}:{$this->windowBucket()}";
    }

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

    private function batchStorePayload(PosTerminal $terminal, int $count): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-TEST',
            'terminal_id' => $terminal->id,
            'transactions' => array_map(fn () => [
                'transaction_id' => (string) Str::uuid(),
                'hardware_id' => $terminal->serial_number,
                'gross_sales' => 100.0,
                'net_sales' => 100.0,
                'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                'payload_checksum' => hash('sha256', (string) Str::uuid()),
                'items' => [
                    ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                ],
            ], range(1, $count)),
        ];
    }
}
