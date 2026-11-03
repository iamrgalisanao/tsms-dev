<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Http\Request;
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

    public function test_legacy_api_flag_does_not_reopen_an_unauthenticated_v1_status_route(): void
    {
        // routes/api.php reads TSMS_ENABLE_LEGACY_API at registration time, so the
        // application (and its routes) must be rebuilt with the flag on.
        $this->setLegacyApiFlag('true');

        try {
            $this->refreshApplication();

            $legacyBatch = app('router')->getRoutes()->match(Request::create('/api/v1/transactions/batch', 'POST'));
            $this->assertSame('retiredBatch', $legacyBatch->getActionMethod(), 'Legacy route block was not registered.');

            $response = $this->getJson('/api/v1/transactions/'.$this->transaction->id.'/status');

            $response->assertStatus(401);
            $response->assertJsonMissingPath('data.gross_sales');
            $response->assertJsonMissingPath('data.customer_code');
        } finally {
            $this->setLegacyApiFlag(null);
            $this->refreshApplication();
        }
    }

    public function test_authenticated_v1_status_route_still_works_for_the_owning_terminal(): void
    {
        Sanctum::actingAs($this->terminal, ['transaction:read']);

        $this->getJson('/api/v1/transactions/'.$this->transaction->transaction_id.'/status')
            ->assertOk()
            ->assertJsonPath('data.transaction_id', $this->transaction->transaction_id);
    }

    private function setLegacyApiFlag(?string $value): void
    {
        if ($value === null) {
            putenv('TSMS_ENABLE_LEGACY_API');
            unset($_ENV['TSMS_ENABLE_LEGACY_API'], $_SERVER['TSMS_ENABLE_LEGACY_API']);

            return;
        }

        putenv("TSMS_ENABLE_LEGACY_API={$value}");
        $_ENV['TSMS_ENABLE_LEGACY_API'] = $value;
        $_SERVER['TSMS_ENABLE_LEGACY_API'] = $value;
    }
}
