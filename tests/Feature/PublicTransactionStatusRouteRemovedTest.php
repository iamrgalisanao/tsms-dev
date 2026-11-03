<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The unauthenticated GET /api/transactions/{id}/status route bypassed tenant
 * scoping (no authenticated terminal), so any transaction's amounts and
 * customer code could be read by sequential id. It is removed; status is only
 * available on the authenticated, tenant-scoped /api/v1 route.
 */
class PublicTransactionStatusRouteRemovedTest extends TestCase
{
    private Tenant $tenant;

    private PosTerminal $terminal;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['trade_name' => 'Status Route Tenant']);
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
        ]);
    }

    protected function tearDown(): void
    {
        Transaction::withoutGlobalScopes()->whereKey($this->transaction->id)->delete();
        PosTerminal::withoutGlobalScopes()->whereKey($this->terminal->id)->delete();
        Tenant::query()->whereKey($this->tenant->id)->delete();

        parent::tearDown();
    }

    public function test_public_status_route_no_longer_returns_transaction_data(): void
    {
        $response = $this->getJson('/api/transactions/'.$this->transaction->id.'/status');

        $this->assertContains($response->getStatusCode(), [401, 404, 405]);
        $response->assertJsonMissingPath('data.gross_sales');
        $response->assertJsonMissingPath('data.customer_code');

        $this->getJson('/api/transactions/'.$this->transaction->transaction_id.'/status')
            ->assertJsonMissingPath('data.gross_sales');
    }

    public function test_authenticated_v1_status_route_requires_a_token(): void
    {
        $this->getJson('/api/v1/transactions/'.$this->transaction->id.'/status')
            ->assertStatus(401);
    }

    public function test_authenticated_v1_status_route_still_works_for_the_owning_terminal(): void
    {
        Sanctum::actingAs($this->terminal, ['transaction:read']);

        $this->getJson('/api/v1/transactions/'.$this->transaction->transaction_id.'/status')
            ->assertOk()
            ->assertJsonPath('data.transaction_id', $this->transaction->transaction_id);
    }
}
