<?php

namespace Tests\Feature\Ingestion;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Models\User;
use App\Notifications\IntakeRejectionAlert;
use App\Services\Monitoring\IntakeRejectionMonitor;
use App\Services\NotificationService;
use App\Services\PayloadChecksumService;
use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * US3 (T031): rejection counters and IntakeRejectionMonitor alerts.
 * Assertions are scoped to this test's tenant/terminal because of the
 * known RefreshDatabase isolation leak (rows from other tests may exist).
 */
class IntakeRejectionMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $tenant;

    private PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));

        config()->set('ingestion.rejection_alerts.enabled', true);
        config()->set('ingestion.rejection_alerts.window_minutes', 60);
        config()->set('ingestion.rejection_alerts.threshold', 3);
        config()->set('ingestion.rejection_alerts.no_accept_hours', 24);
        config()->set('ingestion.rejection_alerts.cooldown_minutes', 360);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->tenant = Tenant::factory()->create();
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->clearCooldowns($this->terminal->id);
    }

    protected function tearDown(): void
    {
        $this->clearCooldowns($this->terminal->id);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_persist_rejection_increments_code_tenant_and_terminal_counters(): void
    {
        Carbon::setTestNow();
        $code = 'HARDWARE_ID_MISMATCH';
        $names = [
            'intake.rejected_count',
            "intake.rejected.code.{$code}",
            "intake.rejected.tenant.{$this->tenant->id}",
            "intake.rejected.terminal.{$this->terminal->id}.{$code}",
        ];
        $before = array_map(fn ($n) => (int) Metrics::get($n, 0), $names);

        $payload = $this->officialPayload('FOREIGN-HARDWARE');
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->terminal->generateAccessToken(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/transactions/official', $payload)
            ->assertStatus(403)
            ->assertJsonPath('error_code', $code);

        foreach ($names as $i => $name) {
            $this->assertSame($before[$i] + 1, (int) Metrics::get($name, 0), $name);
        }
    }

    public function test_threshold_rejections_in_window_raise_exactly_one_spike_alert(): void
    {
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));

        $this->monitor()->check();

        $sent = $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE);
        $this->assertCount(1, $sent);
        $alert = $sent->first();
        $this->assertSame($this->tenant->id, $alert->tenantId);
        $this->assertSame($this->terminal->id, $alert->terminalId);
        $this->assertSame('HARDWARE_ID_MISMATCH', $alert->errorCode);
        $this->assertSame(3, $alert->count);
        $this->assertSame(60, $alert->windowMinutes);
        $this->assertDatabaseHas('system_logs', [
            'type' => 'intake_rejection',
            'log_type' => 'REJECTION_SPIKE_ALERT',
            'terminal_uid' => (string) $this->terminal->id,
        ]);
    }

    public function test_second_check_within_cooldown_sends_no_new_alert(): void
    {
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));

        $this->monitor()->check();
        $this->monitor()->check();

        $this->assertCount(1, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
        $this->assertCount(1, $this->sentAlerts(IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES));
    }

    public function test_cooldown_expiry_allows_the_alert_to_fire_again(): void
    {
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));
        $this->monitor()->check();
        $this->assertCount(1, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));

        Carbon::setTestNow(now()->addMinutes(361));
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));
        $this->monitor()->check();

        $this->assertCount(2, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    public function test_non_rejected_rows_above_threshold_raise_no_spike_alert(): void
    {
        $this->seedIntakes(5, 'QUEUED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));

        $this->monitor()->check();

        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    public function test_delivery_failure_does_not_skip_other_rows_or_spend_the_cooldown(): void
    {
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));
        $this->seedIntakes(3, 'REJECTED', 'STRUCTURAL_VALIDATION_FAILURE', now()->subMinutes(10));

        $terminalId = $this->terminal->id;
        $fake = new class($terminalId) extends NotificationService
        {
            public ?string $failedCode = null;

            public function __construct(private int $terminalId)
            {
                parent::__construct();
            }

            public function notifyAdmins(\Illuminate\Notifications\Notification $notification): void
            {
                if ($this->failedCode === null
                    && $notification instanceof IntakeRejectionAlert
                    && $notification->terminalId === $this->terminalId
                    && $notification->alertType === IntakeRejectionAlert::TYPE_REJECTION_SPIKE) {
                    $this->failedCode = $notification->errorCode;

                    throw new \RuntimeException('forced delivery failure');
                }

                parent::notifyAdmins($notification);
            }
        };
        $this->app->instance(NotificationService::class, $fake);
        Log::spy();

        $this->monitor()->check();

        $this->assertNotNull($fake->failedCode);
        $first = $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE);
        $this->assertCount(1, $first);
        $this->assertNotSame($fake->failedCode, $first->first()->errorCode);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === 'Intake rejection alert delivery failed'
                && $context['exception'] === \RuntimeException::class
                && $context['terminal_id'] === $terminalId
                && $context['error_code'] === $fake->failedCode)
            ->once();

        $this->monitor()->check();

        $second = $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE);
        $this->assertCount(2, $second);
        $this->assertEqualsCanonicalizing(
            ['HARDWARE_ID_MISMATCH', 'STRUCTURAL_VALIDATION_FAILURE'],
            $second->pluck('errorCode')->all()
        );
    }

    public function test_below_threshold_raises_no_spike_alert(): void
    {
        $this->seedIntakes(2, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));

        $this->monitor()->check();

        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    public function test_rejections_outside_window_raise_no_spike_alert(): void
    {
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(61));

        $this->monitor()->check();

        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    public function test_terminal_with_only_rejections_in_24h_raises_no_accepted_sales_alert(): void
    {
        $this->seedIntakes(1, 'REJECTED', 'STRUCTURAL_VALIDATION_FAILURE', now()->subHours(5));

        $this->monitor()->check();

        $sent = $this->sentAlerts(IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES);
        $this->assertCount(1, $sent);
        $this->assertSame($this->tenant->id, $sent->first()->tenantId);
        $this->assertSame(1, $sent->first()->count);
        $this->assertSame(24 * 60, $sent->first()->windowMinutes);
        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    public function test_terminal_with_an_accepted_intake_in_24h_raises_no_no_accepted_sales_alert(): void
    {
        $this->seedIntakes(2, 'REJECTED', 'STRUCTURAL_VALIDATION_FAILURE', now()->subHours(5));
        $this->seedIntakes(1, 'QUEUED', null, now()->subHours(20));

        $this->monitor()->check();

        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES));
    }

    public function test_disabled_config_raises_no_alerts(): void
    {
        config()->set('ingestion.rejection_alerts.enabled', false);
        $this->seedIntakes(3, 'REJECTED', 'HARDWARE_ID_MISMATCH', now()->subMinutes(10));

        $this->monitor()->check();

        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_NO_ACCEPTED_SALES));
    }

    public function test_database_error_inside_check_is_logged_and_not_thrown(): void
    {
        Log::spy();
        $monitor = new class(app(NotificationService::class)) extends IntakeRejectionMonitor
        {
            protected function query(string $sql, array $bindings): array
            {
                throw new QueryException('mysql', $sql, $bindings, new \RuntimeException('forced failure'));
            }
        };

        $monitor->check();

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => $message === 'IntakeRejectionMonitor check failed'
                && $context['exception'] === QueryException::class)
            ->twice();
        $this->assertCount(0, $this->sentAlerts(IntakeRejectionAlert::TYPE_REJECTION_SPIKE));
    }

    private function monitor(): IntakeRejectionMonitor
    {
        return app(IntakeRejectionMonitor::class);
    }

    private function sentAlerts(string $type)
    {
        return Notification::sent($this->admin, IntakeRejectionAlert::class)
            ->filter(fn (IntakeRejectionAlert $n) => $n->alertType === $type && $n->terminalId === $this->terminal->id)
            ->values();
    }

    private function seedIntakes(int $count, string $status, ?string $code, Carbon $receivedAt): void
    {
        for ($i = 0; $i < $count; $i++) {
            TransactionIntake::create([
                'submission_uuid' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'terminal_id' => $this->terminal->id,
                'payload_checksum' => 'NONE',
                'payload' => ['seed' => true],
                'payload_size_bytes' => 15,
                'source_ip' => '127.0.0.1',
                'intake_status' => $status,
                'last_error_code' => $code,
                'trace_id' => (string) Str::uuid(),
                'received_at' => $receivedAt,
            ]);
        }
    }

    private function clearCooldowns(int $terminalId): void
    {
        foreach (['HARDWARE_ID_MISMATCH', 'STRUCTURAL_VALIDATION_FAILURE'] as $code) {
            RateLimiter::clear("intake-rejection-alert:rejection_spike:{$terminalId}:{$code}");
        }
        RateLimiter::clear("intake-rejection-alert:no_accepted_sales:{$terminalId}:ALL");
    }

    private function officialPayload(string $hardwareId): array
    {
        $checksum = new PayloadChecksumService;
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'RM-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => '100.00',
            'net_sales' => '89.29',
            'promo_status' => 'NONE',
            'customer_code' => 'C-MONITOR',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
                ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => '10.71'],
                ['tax_type' => 'VATABLE_SALES', 'amount' => '89.29'],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
            ],
        ];
        $transaction['payload_checksum'] = $checksum->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $payload['payload_checksum'] = $checksum->computeChecksum($payload);

        return $payload;
    }
}
