<?php

namespace Tests\Feature\Ingestion;

use App\Models\PosTerminal;
use App\Models\SystemLog;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Contract: specs/003-ingestion-hardening/contracts/batch-retirement.md.
 *
 * POST /api/v1/transactions/batch no longer records anything. It stays
 * registered behind auth:sanctum + abilities:transaction:create so that
 * unauthenticated probes still get 401, and every authenticated request gets
 * a fixed 410 pointing at /api/v1/transactions/official.
 */
class BatchRetirementTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/transactions/batch';

    private const RETIRED_BODY = [
        'success' => false,
        'error_code' => 'ENDPOINT_RETIRED',
        'message' => 'The batch endpoint has been retired. Submit transactions to /api/v1/transactions/official.',
        'replacement' => '/api/v1/transactions/official',
    ];

    public function test_authenticated_request_with_valid_legacy_batch_body_gets_410_and_records_nothing(): void
    {
        Queue::fake();
        Log::spy();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();
        $payload = $this->legacyBatchPayload($terminal);

        $response = $this->postJson(self::URI, $payload, [
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
            'X-Request-Id' => 'batch-retired-trace',
        ]);

        $response->assertStatus(410);
        $this->assertSame(self::RETIRED_BODY, $response->json());

        $this->assertNothingRecorded($terminal, $systemLogsBefore);
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
        ]);

        Log::shouldHaveReceived('warning')
            ->with('batch endpoint retired: request refused', Mockery::on(
                fn (array $context) => $context === [
                    'terminal_id' => $terminal->id,
                    'tenant_id' => $tenant->id,
                    'correlation_id' => 'batch-retired-trace',
                ]
            ))
            ->once();
    }

    public function test_request_without_token_gets_401(): void
    {
        Queue::fake();
        [, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();

        $response = $this->postJson(self::URI, $this->legacyBatchPayload($terminal));

        $response->assertStatus(401);
        $this->assertNotSame('ENDPOINT_RETIRED', $response->json('error_code'));
        $this->assertNothingRecorded($terminal, $systemLogsBefore);
    }

    public function test_token_without_transaction_create_ability_gets_403(): void
    {
        Queue::fake();
        [, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();
        $token = $terminal->createToken('heartbeat-only', ['heartbeat:send'])->plainTextToken;

        $response = $this->postJson(self::URI, $this->legacyBatchPayload($terminal), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
        $this->assertNotSame('ENDPOINT_RETIRED', $response->json('error_code'));
        $this->assertNothingRecorded($terminal, $systemLogsBefore);
    }

    public function test_oversized_body_gets_410_without_payload_size_rejection(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_payload_bytes', 50);
        [, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();

        $response = $this->postJson(self::URI, $this->legacyBatchPayload($terminal, 20), [
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
        ]);

        $response->assertStatus(410);
        $this->assertSame(self::RETIRED_BODY, $response->json());
        $this->assertNothingRecorded($terminal, $systemLogsBefore);
    }

    public function test_malformed_body_gets_410_without_validation(): void
    {
        Queue::fake();
        [, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();

        $response = $this->call('POST', self::URI, [], [], [], $this->server([
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]), '{"batch_id": "x", "transactions": [ {not json');

        $response->assertStatus(410);
        $this->assertSame(self::RETIRED_BODY, $response->json());
        $this->assertNothingRecorded($terminal, $systemLogsBefore);
    }

    public function test_empty_body_gets_410(): void
    {
        Queue::fake();
        [, $terminal] = $this->seedTenantAndTerminal();
        $systemLogsBefore = SystemLog::count();

        $response = $this->postJson(self::URI, [], [
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
        ]);

        $response->assertStatus(410);
        $this->assertSame(self::RETIRED_BODY, $response->json());
        $this->assertNothingRecorded($terminal, $systemLogsBefore);
    }

    private function assertNothingRecorded(PosTerminal $terminal, int $systemLogsBefore): void
    {
        $this->assertSame(0, Transaction::where('terminal_id', $terminal->id)->count());
        $this->assertSame(0, TransactionIntake::where('terminal_id', $terminal->id)->count());
        $this->assertSame($systemLogsBefore, SystemLog::count());
        Queue::assertNothingPushed();
    }

    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    /**
     * The request shape the retired batchStore() used to accept.
     */
    private function legacyBatchPayload(PosTerminal $terminal, int $count = 1): array
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
