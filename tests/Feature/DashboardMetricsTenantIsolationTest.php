<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardMetricsTenantIsolationTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Asia/Manila'));
        $this->resetDashboardTables();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['admin', 'manager', 'finance', 'commercial'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->tenantA = Tenant::factory()->create(['trade_name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['trade_name' => 'Tenant B']);
    }

    private function resetDashboardTables(): void
    {
        DB::table('transaction_intake')->delete();
        Transaction::withoutGlobalScopes()->delete();
        PosTerminal::withoutGlobalScopes()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_tenant_user_cannot_override_tenant_id_and_receives_tenant_scoped_metrics(): void
    {
        $this->seedDashboardData();

        $user = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $user->assignRole('manager');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics?tenant_id=' . $this->tenantB->id . '&start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');

        $response->assertOk();
        $response->assertJsonPath('total_sales.current', 100);
        $response->assertJsonPath('total_transactions.current', 1);
        $response->assertJsonPath('active_terminals.current', 1);
        $response->assertJsonPath('active_terminals.total', 1);
        $response->assertJsonPath('active_tenants.current', 1);
        $response->assertJsonPath('active_tenants.total', 1);
        $response->assertJsonPath('exceptions.missing_uploads', 1);
        $this->assertSame(['Tenant A'], collect($response->json('top_tenants'))->pluck('trade_name')->all());
        $this->assertEquals([100.0], $response->json('total_sales.sparkline'));
    }

    public function test_tenantless_non_admin_user_is_denied_without_reusable_metrics_cache(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('manager');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06');

        $response->assertForbidden();
        $this->assertFalse(Cache::has('dashboard.api_metrics.deny.2026-08-06.2026-08-06.Asia/Manila'));
    }

    public function test_dashboard_metrics_cache_is_isolated_between_admin_and_tenant_orderings(): void
    {
        $this->seedDashboardData();

        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('admin');

        $tenantUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $tenantUser->assignRole('manager');

        Sanctum::actingAs($admin);
        $adminFirst = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $adminFirst->assertOk()->assertJsonPath('total_sales.current', 300);

        Sanctum::actingAs($tenantUser);
        $tenantSecond = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $tenantSecond->assertOk()->assertJsonPath('total_sales.current', 100);

        Cache::flush();

        Sanctum::actingAs($tenantUser);
        $tenantFirst = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $tenantFirst->assertOk()->assertJsonPath('total_sales.current', 100);

        Sanctum::actingAs($admin);
        $adminSecond = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $adminSecond->assertOk()->assertJsonPath('total_sales.current', 300);
    }

    public function test_dashboard_metrics_cache_is_isolated_between_tenants_and_date_timezone_parameters(): void
    {
        $this->seedDashboardData();
        $this->createTransaction($this->tenantA, 500, Carbon::parse('2026-08-05 10:00:00', 'Asia/Manila'));

        $tenantAUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $tenantAUser->assignRole('manager');

        $tenantBUser = User::factory()->create(['tenant_id' => $this->tenantB->id]);
        $tenantBUser->assignRole('manager');

        Sanctum::actingAs($tenantAUser);
        $tenantA = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $tenantA->assertOk()->assertJsonPath('total_sales.current', 100);

        Sanctum::actingAs($tenantBUser);
        $tenantB = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=Asia/Manila');
        $tenantB->assertOk()->assertJsonPath('total_sales.current', 200);

        Sanctum::actingAs($tenantAUser);
        $differentDate = $this->getJson('/api/dashboard/metrics?start_date=2026-08-05&end_date=2026-08-05&timezone=Asia/Manila');
        $differentDate->assertOk()->assertJsonPath('total_sales.current', 500);

        $differentTimezone = $this->getJson('/api/dashboard/metrics?start_date=2026-08-06&end_date=2026-08-06&timezone=UTC');
        $differentTimezone->assertOk()->assertJsonPath('total_sales.current', 100);

        $this->assertTrue(Cache::has('dashboard.api_metrics.tenant.' . $this->tenantA->id . '.2026-08-06.2026-08-06.Asia/Manila'));
        $this->assertTrue(Cache::has('dashboard.api_metrics.tenant.' . $this->tenantB->id . '.2026-08-06.2026-08-06.Asia/Manila'));
        $this->assertTrue(Cache::has('dashboard.api_metrics.tenant.' . $this->tenantA->id . '.2026-08-05.2026-08-05.Asia/Manila'));
        $this->assertTrue(Cache::has('dashboard.api_metrics.tenant.' . $this->tenantA->id . '.2026-08-06.2026-08-06.UTC'));
    }

    private function seedDashboardData(): void
    {
        $this->createTransaction($this->tenantA, 100, Carbon::parse('2026-08-06 10:00:00', 'Asia/Manila'));
        $this->createTransaction($this->tenantB, 200, Carbon::parse('2026-08-06 11:00:00', 'Asia/Manila'));

        DB::table('transaction_intake')->insert([
            [
                'submission_uuid' => 'missing-a-' . $this->tenantA->id,
                'tenant_id' => $this->tenantA->id,
                'terminal_id' => PosTerminal::where('tenant_id', $this->tenantA->id)->value('id'),
                'payload_checksum' => 'missing-a-checksum-' . $this->tenantA->id,
                'payload' => json_encode(['submission_uuid' => 'missing-a-' . $this->tenantA->id]),
                'payload_size_bytes' => 10,
                'trace_id' => (string) \Illuminate\Support\Str::uuid(),
                'processing_status' => 'PROCESSED',
                'received_at' => '2026-08-06 10:15:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'submission_uuid' => 'missing-b-' . $this->tenantB->id,
                'tenant_id' => $this->tenantB->id,
                'terminal_id' => PosTerminal::where('tenant_id', $this->tenantB->id)->value('id'),
                'payload_checksum' => 'missing-b-checksum-' . $this->tenantB->id,
                'payload' => json_encode(['submission_uuid' => 'missing-b-' . $this->tenantB->id]),
                'payload_size_bytes' => 10,
                'trace_id' => (string) \Illuminate\Support\Str::uuid(),
                'processing_status' => 'PROCESSED',
                'received_at' => '2026-08-06 11:15:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createTransaction(Tenant $tenant, int $grossSales, Carbon $timestamp): void
    {
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'status_id' => 1,
        ]);

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => $timestamp,
            'gross_sales' => $grossSales,
            'net_sales' => $grossSales,
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'job_status' => Transaction::JOB_STATUS_COMPLETED,
            'completed_at' => $timestamp,
            'submission_uuid' => 'submitted-' . $tenant->id . '-' . $grossSales . '-' . $timestamp->timestamp,
        ]);
    }
}
