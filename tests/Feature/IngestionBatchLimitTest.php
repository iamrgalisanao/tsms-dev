<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Support\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IngestionBatchLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_endpoint_rejects_batch_over_max_count_with_422(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_batch_count', 3);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, $submissionUuid, 4);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('max_batch_count', 3);

        $this->assertNotEmpty($response->json('correlation_id'));

        // The batch-count guard runs before Stage 1 structural validation
        // and before any intake persistence, so no audit/rejection row
        // should exist for this submission at all.
        $this->assertDatabaseMissing('transaction_intake', [
            'submission_uuid' => $submissionUuid,
        ]);
        Queue::assertNothingPushed();

        // WU4 (T053 remainder): batch-size rejection-reason counter.
        $this->assertSame(1, Metrics::get('ingestion.rejected.batch_size'));
    }

    public function test_official_endpoint_accepts_batch_within_max_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_batch_count', 500);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, $submissionUuid, 4);

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $this->assertNotSame('BATCH_LIMIT_EXCEEDED', $response->json('error_code'));
    }

    public function test_batch_endpoint_rejects_batch_over_max_count_with_422(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_batch_count', 2);

        [, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->batchStorePayload($terminal, 3);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('max_batch_count', 2);

        $this->assertNotEmpty($response->json('correlation_id'));

        foreach ($payload['transactions'] as $transactionData) {
            $this->assertDatabaseMissing('transactions', [
                'transaction_id' => $transactionData['transaction_id'],
            ]);
        }
        Queue::assertNothingPushed();

        // WU4 (T053 remainder): batch-size rejection-reason counter.
        $this->assertSame(1, Metrics::get('ingestion.rejected.batch_size'));
    }

    public function test_batch_endpoint_batch_limit_guard_precedes_terminal_id_db_lookup(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_batch_count', 2);

        [, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->batchStorePayload($terminal, 3);

        // Declare a terminal_id that does not exist in pos_terminals at all.
        // Authentication still uses a real terminal's token (route middleware
        // never inspects the payload's terminal_id field), so this request
        // reaches batchStore(). If the batch-count guard did not win the race
        // against $request->validate()'s DB-backed `exists:pos_terminals,id`
        // rule, this would instead fail as a Laravel validation error (no
        // 'error_code' key, a 'terminal_id' entry under 'errors') rather than
        // as BATCH_LIMIT_EXCEEDED.
        $payload['terminal_id'] = $terminal->id + 999999;

        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'BATCH_LIMIT_EXCEEDED')
            ->assertJsonPath('max_batch_count', 2);

        $this->assertArrayNotHasKey('errors', $response->json());
        $this->assertNotEmpty($response->json('correlation_id'));

        foreach ($payload['transactions'] as $transactionData) {
            $this->assertDatabaseMissing('transactions', [
                'transaction_id' => $transactionData['transaction_id'],
            ]);
        }
        Queue::assertNothingPushed();
    }

    public function test_batch_endpoint_accepts_batch_within_max_count(): void
    {
        Queue::fake();
        config()->set('tsms.intake.max_batch_count', 500);

        [, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->batchStorePayload($terminal, 3);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, $this->headersFor($terminal));

        $this->assertNotSame('BATCH_LIMIT_EXCEEDED', $response->json('error_code'));
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

    /**
     * A minimal (deliberately not fully structurally valid) official batch
     * payload. The batch-count guard runs before structural validation, so
     * the items themselves don't need to satisfy the full adjustments/taxes
     * schema for these tests.
     */
    private function officialBatchPayload(int $tenantId, int $terminalId, string $submissionUuid, int $count): array
    {
        return [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => $count,
            'transactions' => array_map(
                fn () => ['transaction_id' => (string) Str::uuid()],
                range(1, $count)
            ),
            'payload_checksum' => str_repeat('a', 64),
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
