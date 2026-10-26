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

}
