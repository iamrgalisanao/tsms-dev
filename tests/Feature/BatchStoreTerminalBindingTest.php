<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BatchStoreTerminalBindingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function batchPayload(int $terminalId, ?string $hardwareId = null): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-TEST',
            'terminal_id' => $terminalId,
            'transactions' => [
                [
                    'transaction_id' => (string) Str::uuid(),
                    'hardware_id' => $hardwareId ?? 'HW-TEST',
                    'gross_sales' => 100.0,
                    'net_sales' => 100.0,
                    'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                    'payload_checksum' => hash('sha256', Str::uuid()->toString()),
                    'items' => [
                        ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                    ],
                ],
            ],
        ];
    }

    public function test_batch_store_rejects_terminal_id_that_does_not_match_authenticated_token(): void
    {
        [, $ownTerminal] = $this->seedTenantAndTerminal();
        [$otherTenant, $otherTerminal] = $this->seedTenantAndTerminal();

        $token = $ownTerminal->generateAccessToken();
        $payload = $this->batchPayload($otherTerminal->id);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
        ]);
    }

    public function test_batch_store_accepts_own_terminal_id(): void
    {
        [, $terminal] = $this->seedTenantAndTerminal();
        $token = $terminal->generateAccessToken();
        $payload = $this->batchPayload($terminal->id);

        $response = $this->postJson('/api/v1/transactions/batch', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $payload['transactions'][0]['transaction_id'],
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
        ]);
    }
}
