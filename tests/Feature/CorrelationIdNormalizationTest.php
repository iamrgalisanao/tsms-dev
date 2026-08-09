<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\FakesCircuitBreakerRedis;

/**
 * WU1: correlation-ID normalization. Covers AttachCorrelationId's 4-tier
 * ingress precedence (existing attribute > X-Request-Id > X-Correlation-ID
 * compatibility fallback > generated UUID) and confirms that
 * TransactionIntakeService and CircuitBreakerMiddleware read only the
 * normalized `correlation_id` request attribute rather than independently
 * re-deriving it from raw headers. See
 * specs/001-100-tenant-resilience/plan.md, "WU1 — Correlation ID
 * normalization and shared log context".
 */
class CorrelationIdNormalizationTest extends TestCase
{
    use FakesCircuitBreakerRedis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate correlation-ID behavior from the unrelated queue-depth
        // backpressure gate, mirroring tests/Feature/IngestionCircuitBreakerTest.php.
        config()->set('tsms.intake.backpressure.enabled', false);

        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.redis_connection', 'default');
        config()->set('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:');
        config()->set('tsms.circuit_breaker.failure_threshold', 3);
        config()->set('tsms.circuit_breaker.reset_timeout_seconds', 60);
        config()->set('tsms.circuit_breaker.state_ttl_seconds', 3600);

        $this->fakeCircuitBreakerRedis();
    }

    public function test_conflicting_correlation_headers_prefer_x_request_id_and_log_a_warning(): void
    {
        Queue::fake();
        Log::spy();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $requestId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $headers = $this->headersFor($terminal) + [
            'X-Request-Id' => $requestId,
            'X-Correlation-ID' => $correlationId,
        ];

        $response = $this->postJson('/api/v1/transactions/official', $payload, $headers)
            ->assertStatus(202);

        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
        $this->assertSame($requestId, $response->json('correlation_id'));

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame(
            $requestId,
            $intake->trace_id,
            'X-Request-Id must win over X-Correlation-ID when both are present with different values'
        );

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'X-Request-Id and X-Correlation-ID'));
    }

    public function test_x_correlation_id_is_honored_as_compatibility_fallback_when_x_request_id_is_absent(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $correlationId = (string) Str::uuid();

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $headers = $this->headersFor($terminal) + [
            'X-Correlation-ID' => $correlationId,
        ];

        $response = $this->postJson('/api/v1/transactions/official', $payload, $headers)
            ->assertStatus(202);

        $this->assertSame($correlationId, $response->headers->get('X-Request-Id'));
        $this->assertSame($correlationId, $response->json('correlation_id'));

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame($correlationId, $intake->trace_id);
    }

    public function test_generates_a_uuid_when_neither_correlation_header_is_present(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(202);

        $generated = $response->headers->get('X-Request-Id');
        $this->assertNotEmpty($generated);
        $this->assertTrue(Str::isUuid($generated), 'expected a generated UUID when neither correlation header is present');

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame($generated, $intake->trace_id);
    }

    public function test_transaction_intake_service_uses_the_normalized_attribute_not_a_raw_x_correlation_id_header(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $normalizedCorrelationId = (string) Str::uuid();
        $rawHeaderCorrelationId = (string) Str::uuid();

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $request = Request::create(
            '/api/v1/transactions/official',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CORRELATION_ID' => $rawHeaderCorrelationId],
            json_encode($payload)
        );
        $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
        $request->setUserResolver(fn () => $terminal);

        // Simulate AttachCorrelationId having already normalized this
        // request upstream, to a value that deliberately differs from the
        // raw X-Correlation-ID header still present on the request. If
        // TransactionIntakeService ever re-derived its own trace ID from
        // that header instead of reading this attribute, the assertions
        // below would fail.
        $request->attributes->set('correlation_id', $normalizedCorrelationId);

        $service = app(TransactionIntakeService::class);
        $result = $service->handleOfficialIntake($request);

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame($normalizedCorrelationId, $result['correlation_id']);

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame($normalizedCorrelationId, $intake->trace_id);
        $this->assertNotSame($rawHeaderCorrelationId, $intake->trace_id);
    }

    public function test_circuit_breaker_rejection_carries_the_normalized_correlation_id(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $shouldFail = true;
        TransactionIntake::creating(function () use (&$shouldFail) {
            if ($shouldFail) {
                throw new \RuntimeException('Simulated DB outage');
            }
        });

        // Drive exactly failure_threshold (3) infrastructure failures to open the breaker.
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
                ->assertStatus(503);
        }

        $requestId = (string) Str::uuid();
        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $headers = $this->headersFor($terminal) + ['X-Request-Id' => $requestId];

        $response = $this->postJson('/api/v1/transactions/official', $payload, $headers)
            ->assertStatus(503)
            ->assertJson([
                'error' => 'Service unavailable',
                'service' => 'transaction-intake',
                'message' => 'Circuit is open due to multiple failures',
            ]);

        $this->assertSame($requestId, $response->json('correlation_id'));
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
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
            'receipt_no' => 'CID-'.Str::upper(Str::random(8)),
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
