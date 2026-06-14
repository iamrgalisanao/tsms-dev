<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TenantInactivityAlert;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantInactivityAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.tenant_inactivity_enabled' => true,
            'notifications.tenant_inactivity_threshold_minutes' => 30,
            'notifications.tenant_inactivity_cooldown_minutes' => 60,
            'notifications.tenant_inactivity_emails' => [],
            'mail.default' => 'array',
        ]);

        Tenant::query()->update(['activity_monitoring_enabled' => false]);
        PosTerminal::query()->update(['activity_monitoring_enabled' => false]);
    }

    public function test_inactivity_alert_uses_monitoring_thresholds_and_includes_terminal_details(): void
    {
        Notification::fake();
        $admin = $this->adminUser();
        $tenant = Tenant::factory()->create([
            'status' => 'Operational',
            'activity_monitoring_enabled' => true,
            'activity_threshold_minutes' => 30,
        ]);
        $terminal = $this->createTerminal($tenant);
        $otherTerminal = $this->createTerminal($tenant);

        $this->createTransaction($terminal, now()->subMinutes(45));
        $this->clearActivityAlertRateLimits($tenant, [$terminal, $otherTerminal]);

        app(NotificationService::class)->checkTenantInactivity();

        Notification::assertSentTo(
            $admin,
            TenantInactivityAlert::class,
            function (TenantInactivityAlert $notification) use ($tenant, $terminal, $otherTerminal) {
                return count($notification->inactiveTenants) === 1
                    && $notification->inactiveTenants[0]['tenant_id'] === $tenant->id
                    && collect($notification->inactiveTerminals)->pluck('terminal_id')->sort()->values()->all()
                        === collect([$terminal->id, $otherTerminal->id])->sort()->values()->all();
            }
        );

        $this->assertDatabaseHas('system_logs', [
            'type' => 'tenant_inactivity',
            'log_type' => 'TENANT_INACTIVITY_ALERT',
            'message' => "Tenant inactivity detected: {$tenant->trade_name}",
        ]);
    }

    public function test_suppressed_tenant_does_not_send_inactivity_alert(): void
    {
        Notification::fake();
        $admin = $this->adminUser();
        $tenant = Tenant::factory()->create([
            'status' => 'Operational',
            'activity_monitoring_enabled' => true,
            'activity_threshold_minutes' => 30,
            'activity_suppressed_until' => now()->addHour(),
            'activity_suppression_reason' => 'Scheduled provider maintenance',
        ]);
        $terminal = $this->createTerminal($tenant);

        $this->createTransaction($terminal, now()->subMinutes(45));
        $this->clearActivityAlertRateLimits($tenant, [$terminal]);

        app(NotificationService::class)->checkTenantInactivity();

        Notification::assertNotSentTo($admin, TenantInactivityAlert::class);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function clearActivityAlertRateLimits(Tenant $tenant, array $terminals): void
    {
        RateLimiter::clear("alerts:tenant-inactivity:{$tenant->id}");

        foreach ($terminals as $terminal) {
            RateLimiter::clear("alerts:terminal-inactivity:{$terminal->id}");
        }
    }

    private function createTerminal(Tenant $tenant): PosTerminal
    {
        return PosTerminal::query()->create([
            'tenant_id' => $tenant->id,
            'serial_number' => 'SN-' . Str::upper(Str::random(8)),
            'machine_number' => 'MN-' . Str::upper(Str::random(6)),
            'is_active' => true,
            'status_id' => 1,
            'activity_monitoring_enabled' => true,
            'activity_threshold_minutes' => 30,
            'expires_at' => now()->addMonth(),
            'registered_at' => now(),
        ]);
    }

    private function createTransaction(PosTerminal $terminal, $timestamp): Transaction
    {
        $payload = [
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'submission_uuid' => (string) Str::uuid(),
            'hardware_id' => 'HW-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $timestamp,
            'gross_sales' => 1000,
            'net_sales' => 900,
            'customer_code' => 'TEST001',
            'payload_checksum' => md5((string) Str::uuid()),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ];

        if (Schema::hasColumn('transactions', 'receipt_no')) {
            $payload['receipt_no'] = 'REC-' . Str::random(8);
        }

        if (Schema::hasColumn('transactions', 'base_amount')) {
            $payload['base_amount'] = 1000;
        }

        return Transaction::query()->create($payload);
    }
}
