<?php

namespace Tests\Feature\API\V1;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderActivityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find(1) ?? Tenant::factory()->create(['id' => 1]);
        $tenant->update([
            'status' => 'Operational',
            'activity_monitoring_enabled' => true,
            'activity_threshold_minutes' => null,
        ]);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);
    }

    public function test_terminal_can_view_scoped_tenant_activity(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'Operational']);
        $otherTerminal = PosTerminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        $this->createTransaction($this->terminal, now()->subHour());
        $this->createTransaction($this->terminal, now()->subDay());
        $this->createIntake($this->terminal, now()->subMinutes(30));
        $this->createTransaction($otherTerminal, now()->subHour());

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $response = $this->getJson('/api/v1/monitoring/tenants/activity');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', $this->terminal->tenant_id)
            ->assertJsonPath('data.0.transactions_today', 1)
            ->assertJsonPath('data.0.transactions_yesterday', 1)
            ->assertJsonPath('data.0.active_terminals_today', 1)
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_terminal_activity_is_scoped_to_authenticated_terminal(): void
    {
        $sameTenantOtherTerminal = PosTerminal::factory()->create([
            'tenant_id' => $this->terminal->tenant_id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        $this->createTransaction($this->terminal, now()->subHour());
        $this->createTransaction($sameTenantOtherTerminal, now()->subHour());

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $response = $this->getJson('/api/v1/monitoring/terminals/activity');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.terminal_id', $this->terminal->id)
            ->assertJsonPath('data.0.transactions_today', 1);
    }

    public function test_monitoring_requires_provider_testing_ability(): void
    {
        Sanctum::actingAs($this->terminal, ['transaction:read']);

        $this->getJson('/api/v1/monitoring/tenants/activity')
            ->assertForbidden();
    }

    public function test_activity_uses_persisted_tenant_threshold_by_default(): void
    {
        $this->terminal->tenant->update([
            'activity_threshold_minutes' => 30,
        ]);

        $this->createTransaction($this->terminal, now()->subMinutes(45));

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $this->getJson('/api/v1/monitoring/tenants/activity')
            ->assertOk()
            ->assertJsonPath('meta.threshold_mode', 'configured')
            ->assertJsonPath('data.0.threshold_minutes', 30)
            ->assertJsonPath('data.0.status', 'silent');
    }

    public function test_activity_can_be_disabled_for_terminal(): void
    {
        $this->terminal->update([
            'activity_monitoring_enabled' => false,
            'activity_threshold_minutes' => 30,
            'activity_monitoring_notes' => 'Seasonal terminal disabled',
        ]);

        $this->createTransaction($this->terminal, now()->subMinutes(45));

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $this->getJson('/api/v1/monitoring/terminals/activity')
            ->assertOk()
            ->assertJsonPath('data.0.threshold_minutes', 30)
            ->assertJsonPath('data.0.monitoring_enabled', false)
            ->assertJsonPath('data.0.monitoring_notes', 'Seasonal terminal disabled')
            ->assertJsonPath('data.0.status', 'inactive_configured');
    }

    public function test_admin_can_update_tenant_monitoring_config(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $suppressedUntil = now()->addHours(2)->toISOString();

        Sanctum::actingAs($admin);

        $this->putJson("/api/monitoring/tenants/{$this->terminal->tenant_id}/config", [
            'activity_monitoring_enabled' => false,
            'activity_threshold_minutes' => 90,
            'activity_monitoring_notes' => 'Closed for maintenance',
            'activity_suppressed_until' => $suppressedUntil,
            'activity_suppression_reason' => 'Provider maintenance window',
        ])
            ->assertOk()
            ->assertJsonPath('data.monitoring_enabled', false)
            ->assertJsonPath('data.threshold_minutes', 90)
            ->assertJsonPath('data.alert_suppression_reason', 'Provider maintenance window');

        $this->assertDatabaseHas('tenants', [
            'id' => $this->terminal->tenant_id,
            'activity_monitoring_enabled' => false,
            'activity_threshold_minutes' => 90,
            'activity_monitoring_notes' => 'Closed for maintenance',
            'activity_suppression_reason' => 'Provider maintenance window',
            'activity_suppressed_by' => $admin->id,
        ]);
    }

    public function test_alert_suppression_is_visible_in_activity_rows(): void
    {
        $this->terminal->tenant->update([
            'activity_threshold_minutes' => 30,
            'activity_suppressed_until' => now()->addHour(),
            'activity_suppression_reason' => 'Known provider maintenance',
        ]);

        $this->createTransaction($this->terminal, now()->subMinutes(45));

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $this->getJson('/api/v1/monitoring/tenants/activity')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'silent')
            ->assertJsonPath('data.0.alert_suppressed', true)
            ->assertJsonPath('data.0.alert_state', 'suppressed')
            ->assertJsonPath('data.0.alert_suppression_reason', 'Known provider maintenance');
    }

    public function test_admin_can_view_daily_heartbeat_report(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $silentTenant = Tenant::factory()->create([
            'status' => 'Operational',
            'activity_threshold_minutes' => 30,
            'activity_suppressed_until' => now()->addHour(),
            'activity_suppression_reason' => 'Expected pause',
        ]);
        $silentTerminal = PosTerminal::factory()->create([
            'tenant_id' => $silentTenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        $inactiveTenant = Tenant::factory()->create([
            'status' => 'Operational',
            'activity_monitoring_enabled' => false,
        ]);
        PosTerminal::factory()->create([
            'tenant_id' => $inactiveTenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        $this->createTransaction($this->terminal, now()->subHour());
        $this->createTransaction($silentTerminal, now()->subMinutes(45));
        $this->createIntake($this->terminal, now()->subMinutes(30));

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/monitoring/activity/daily-report?date=' . now()->toDateString())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.threshold_mode', 'configured')
            ->assertJsonStructure([
                'data' => [
                    'tenants' => [
                        '*' => [
                            'tenant_id',
                            'tenant_name',
                            'customer_code',
                            'status',
                            'transactions_today',
                            'active_terminals_today',
                            'silent_terminals',
                            'threshold_minutes',
                            'monitoring_enabled',
                            'alert_suppressed',
                            'alert_state',
                        ],
                    ],
                ],
            ]);

        $rows = collect($response->json('data.tenants'))->keyBy('tenant_id');
        $summary = $response->json('data.summary');

        $this->assertGreaterThanOrEqual(3, $summary['tracked_tenants']);
        $this->assertGreaterThanOrEqual(1, $summary['active']);
        $this->assertGreaterThanOrEqual(1, $summary['silent']);
        $this->assertGreaterThanOrEqual(1, $summary['inactive_configured']);
        $this->assertGreaterThanOrEqual(1, $summary['suppressed_alerts']);
        $this->assertGreaterThanOrEqual(2, $summary['transactions_today']);
        $this->assertSame('active', $rows[$this->terminal->tenant_id]['status']);
        $this->assertSame('silent', $rows[$silentTenant->id]['status']);
        $this->assertTrue($rows[$silentTenant->id]['alert_suppressed']);
        $this->assertSame('inactive_configured', $rows[$inactiveTenant->id]['status']);
    }

    public function test_admin_can_export_daily_heartbeat_report_csv(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->createTransaction($this->terminal, now()->subHour());

        Sanctum::actingAs($admin);

        $response = $this->get('/api/monitoring/activity/daily-report?format=csv&date=' . now()->toDateString());

        $response->assertOk();
        $this->assertStringContainsString('provider-daily-heartbeat-', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('tenant_id,tenant_name,customer_code,status', $response->streamedContent());
    }

    private function createTransaction(PosTerminal $terminal, $timestamp): Transaction
    {
        return Transaction::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'submission_uuid' => (string) Str::uuid(),
            'receipt_no' => 'REC-' . Str::random(8),
            'transaction_timestamp' => $timestamp,
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ]);
    }

    private function createIntake(PosTerminal $terminal, $receivedAt): TransactionIntake
    {
        return TransactionIntake::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [],
            'payload_size_bytes' => 128,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => 'trace-monitoring',
            'received_at' => $receivedAt,
        ]);
    }
}
