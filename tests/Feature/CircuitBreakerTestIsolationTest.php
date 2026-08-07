<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\CircuitBreaker;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

/**
 * Locks in the fix for the test-isolation gap discovered by a full-suite
 * run: the transaction-intake circuit breaker (App\Services\CircuitBreaker)
 * is backed by real Redis with state that is NOT reset between tests
 * (state_ttl_seconds defaults to 3600s). Before this fix, the breaker
 * defaulted to enabled=true in every environment including testing, so any
 * single test that drove enough genuine 5xx responses through
 * /transactions/official or /transactions/batch (e.g.
 * StoreOfficialResilienceTest, which deliberately throws mid-persist) could
 * trip the breaker open in the *real, shared* Redis instance — and that
 * "open" state then leaked into every subsequent test in the same run that
 * hit those routes, including tests with no relationship whatsoever to
 * circuit-breaker or backpressure behavior, causing them to fail with a
 * false 503 "Circuit is open due to multiple failures".
 *
 * The fix: tsms.circuit_breaker.enabled now defaults to false in the
 * testing environment (see TSMS_CIRCUIT_BREAKER_ENABLED in .env.testing).
 * Only the handful of test files that specifically exercise
 * circuit-breaker behavior opt back in explicitly, and they do so against a
 * faked Redis double (Tests\Traits\FakesCircuitBreakerRedis), never the
 * real shared instance — so they can never leak state into anything else.
 */
class CircuitBreakerTestIsolationTest extends TestCase
{
    use RefreshDatabase, FakesCircuitBreakerRedis;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_circuit_breaker_defaults_to_disabled_in_the_testing_environment(): void
    {
        // This is the actual mechanism that closes the isolation gap: as
        // long as this stays false by default, no test can ever leak an
        // "open" breaker state into another test unless it explicitly opts
        // in (and per the file's own docblock above, every file that does
        // opt in also fakes Redis).
        $this->assertFalse(
            config('tsms.circuit_breaker.enabled'),
            'tsms.circuit_breaker.enabled must default to false in the testing environment '
            . '(set via TSMS_CIRCUIT_BREAKER_ENABLED in .env.testing) so that a real, '
            . 'Redis-backed breaker tripped open by one test cannot leak into unrelated '
            . 'tests later in the same full-suite run.'
        );
    }

    public function test_a_stale_open_breaker_state_does_not_affect_requests_when_disabled_by_default(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        // Isolate this test from the ingestion queue-depth backpressure
        // gate entirely — it exercises the circuit breaker, a distinct
        // concern, and the Redis double below only stubs the
        // circuit-breaker hash commands.
        config()->set('tsms.intake.backpressure.enabled', false);

        // Reproduce, byte-for-byte, the leak this test guards against:
        // explicitly enable the breaker against a Redis double (standing in
        // for the real shared instance a full-suite run would use) and
        // drive genuine infrastructure failures through the real
        // middleware + route until it trips open — exactly what a rogue
        // test elsewhere in the suite could do to the real instance.
        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.redis_connection', 'default');
        config()->set('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:');
        config()->set('tsms.circuit_breaker.failure_threshold', 3);
        config()->set('tsms.circuit_breaker.reset_timeout_seconds', 60);
        config()->set('tsms.circuit_breaker.state_ttl_seconds', 3600);
        $this->fakeCircuitBreakerRedis();

        $shouldFail = true;
        \App\Models\TransactionIntake::creating(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Simulated DB outage');
            }
        });

        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503);
        }

        // Confirm the breaker is genuinely open in the (faked) shared
        // Redis state — this is the exact "leaked" state a rogue test
        // elsewhere in a full-suite run would leave behind.
        $breaker = new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY);
        $this->assertFalse($breaker->isAvailable(), 'Precondition: the breaker must be genuinely open before testing the isolation fix.');

        // Now simulate "the next unrelated test in the suite": config
        // reverts to the real default (no explicit override), the way a
        // fresh test's setUp() would look if it never opts back into
        // circuit-breaker testing. Crucially, the Redis state above is
        // left untouched/open — nothing resets it between tests.
        config()->set('tsms.circuit_breaker.enabled', false);
        $shouldFail = false;

        // A request that would previously have received the leaked
        // "Circuit is open due to multiple failures" 503 must now succeed
        // normally, proving the disabled breaker ignores stale open state
        // entirely rather than merely resetting it.
        $unrelatedPayload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $unrelatedPayload, $this->headersFor($terminal))
            ->assertStatus(202)
            ->assertJsonPath('success', true);
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
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'CBI-' . Str::upper(Str::random(8)),
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
