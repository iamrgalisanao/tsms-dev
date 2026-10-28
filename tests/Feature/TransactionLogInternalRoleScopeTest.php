<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TSMS-0060: internal PITX finance/commercial staff (no tenant_id) must see
 * transaction logs across tenants; tenant-bound and other roles stay scoped.
 */
class TransactionLogInternalRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $txA;

    private Transaction $txB;

    private Tenant $tenantA;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'finance', 'commercial', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->tenantA = Tenant::factory()->create(['trade_name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::factory()->create(['trade_name' => 'Tenant B', 'status' => 'active']);
        $terminalA = PosTerminal::factory()->create(['tenant_id' => $this->tenantA->id, 'status_id' => 1]);
        $terminalB = PosTerminal::factory()->create(['tenant_id' => $tenantB->id, 'status_id' => 1]);

        $this->txA = Transaction::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'terminal_id' => $terminalA->id,
            'transaction_id' => 'TXN-SCOPE-A',
        ]);
        $this->txB = Transaction::factory()->create([
            'tenant_id' => $tenantB->id,
            'terminal_id' => $terminalB->id,
            'transaction_id' => 'TXN-SCOPE-B',
        ]);
    }

    private function visibleIds(User $user)
    {
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/transactions/logs?per_page=100');
        $response->assertOk();

        return collect($response->json('data'))->pluck('transaction_id');
    }

    public static function internalRoles(): array
    {
        return [['admin'], ['finance'], ['commercial']];
    }

    #[DataProvider('internalRoles')]
    public function test_internal_staff_without_tenant_see_all_tenants(string $role): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole($role);

        $ids = $this->visibleIds($user);

        $this->assertTrue($ids->contains('TXN-SCOPE-A'), "{$role} should see tenant A");
        $this->assertTrue($ids->contains('TXN-SCOPE-B'), "{$role} should see tenant B");

        $this->getJson('/api/transactions/logs/'.$this->txB->id)->assertOk();
    }

    public function test_internal_finance_can_filter_to_a_specific_tenant(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('finance');
        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/transactions/logs?tenant_id='.$this->tenantA->id)
            ->assertOk()->json('data'))->pluck('transaction_id');

        $this->assertTrue($ids->contains('TXN-SCOPE-A'));
        $this->assertFalse($ids->contains('TXN-SCOPE-B'));
    }

    public function test_tenant_bound_finance_user_stays_scoped_to_own_tenant(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $user->assignRole('finance');

        $ids = $this->visibleIds($user);

        $this->assertTrue($ids->contains('TXN-SCOPE-A'));
        $this->assertFalse($ids->contains('TXN-SCOPE-B'));
    }

    public function test_manager_without_tenant_is_not_granted_cross_tenant_logs(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('manager');

        $ids = $this->visibleIds($user);

        $this->assertFalse($ids->contains('TXN-SCOPE-A'));
        $this->assertFalse($ids->contains('TXN-SCOPE-B'));
    }
}
