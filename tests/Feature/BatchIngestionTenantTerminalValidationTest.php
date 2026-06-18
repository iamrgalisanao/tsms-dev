<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BatchIngestionTenantTerminalValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create and authenticate a user with Sanctum
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
    }

    public function test_happy_path_matching_tenant_and_terminal(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        $payload = [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => 'tx-1',
                    'amount' => 1000,
                    'currency' => 'PHP',
                    'occurred_at' => now()->toISOString(),
                ],
                [
                    'transaction_id' => 'tx-2',
                    'amount' => 2000,
                    'currency' => 'PHP',
                    'occurred_at' => now()->toISOString(),
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/transactions/batch', $payload);
        $res->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_request_level_mismatch_rejected_with_422(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenantA->id]);

        $payload = [
            'tenant_id' => $tenantB->id, // mismatch
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => 'tx-1',
                    'amount' => 1000,
                    'currency' => 'PHP',
                    'occurred_at' => now()->toISOString(),
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/transactions/batch', $payload);
        $res->assertStatus(422)
            ->assertJson([
                'success' => false,
            ])
            ->assertJsonStructure(['errors' => ['tenant_id']]);
    }

    public function test_item_level_mismatch_marks_item_failed_but_continues_batch(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenantA->id]);

        $payload = [
            'tenant_id' => $tenantA->id,
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => 'tx-1',
                    'tenant_id' => $tenantB->id, // mismatching item-level tenant
                    'amount' => 1000,
                    'currency' => 'PHP',
                    'occurred_at' => now()->toISOString(),
                ],
                [
                    'transaction_id' => 'tx-2',
                    'amount' => 2000,
                    'currency' => 'PHP',
                    'occurred_at' => now()->toISOString(),
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/transactions/batch', $payload);
        $res->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJson(function ($json) {
                // Expect one failed and one processed
                $data = $json->json();
                $this->assertArrayHasKey('failed', $data);
                $this->assertGreaterThanOrEqual(1, $data['failed'] ?? 0);
            });
    }
}
