<?php

namespace Tests\Feature;

use App\Exports\TransactionLogsExport;
use App\Http\Resources\TransactionCollection;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TransactionFailureThresholdExceeded;
use App\Services\DashboardService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-004 slice 3: every status reader follows D1.
 *
 * Fixtures per reader: VALID+COMPLETED, INVALID+COMPLETED, PENDING+FAILED,
 * legacy ''+FAILED (raw write under a non-strict session) and VALID+FAILED.
 * Rows can leak between tests (TestCase refreshApplication issue), so every
 * assertion is scoped to this test's own tenant/rows.
 */
class TransactionStatusReadersTest extends TestCase
{
    private Tenant $tenant;

    private PosTerminal $terminal;

    /** @var array<string, Transaction> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['trade_name' => 'Status Readers Tenant']);
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function seedStatusFixtures(?Carbon $at = null): void
    {
        $at ??= now();
        $make = fn (string $validation, string $job, float $gross) => Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => $at,
            'created_at' => $at,
            'updated_at' => $at,
            'gross_sales' => $gross,
            'net_sales' => $gross,
            'validation_status' => $validation,
            'job_status' => $job,
            'last_error' => $job === 'FAILED' ? 'App\\Jobs\\ProcessTransactionJob has been attempted too many times.' : null,
        ]);

        $this->rows['valid'] = $make('VALID', 'COMPLETED', 100);
        $this->rows['invalid'] = $make('INVALID', 'COMPLETED', 30);
        $this->rows['pending_failed'] = $make('PENDING', 'FAILED', 50);
        $this->rows['blank_failed'] = $this->legacyBlank($make('PENDING', 'FAILED', 10));
        $this->rows['valid_failed'] = $make('VALID', 'FAILED', 40);
    }

    private function legacyBlank(Transaction $tx): Transaction
    {
        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::table('transactions')->where('id', $tx->id)->update(['validation_status' => 'FAILED']); // stored as ''
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
        $this->assertSame('', DB::table('transactions')->where('id', $tx->id)->value('validation_status'));

        return $tx->refresh();
    }

    private function ownIds(): array
    {
        return collect($this->rows)->map->id->values()->all();
    }

    private function adminUser(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    // --- Model -------------------------------------------------------------------

    public function test_model_scopes_and_display_helpers_follow_d1(): void
    {
        $this->seedStatusFixtures();
        $ids = $this->ownIds();

        $errorFailed = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'validation_status' => 'ERROR',
            'job_status' => 'FAILED',
        ]);
        $this->assertEqualsCanonicalizing(
            [$this->rows['pending_failed']->id, $this->rows['blank_failed']->id, $errorFailed->id],
            Transaction::processingFailed()->whereIn('id', array_merge($ids, [$errorFailed->id]))->pluck('id')->all(),
            "processing failed = job FAILED and validation NOT IN ('VALID','INVALID')"
        );
        $this->assertSame('INVALID', $errorFailed->statusLabel());
        $this->assertSame([$this->rows['invalid']->id], Transaction::failed()->whereIn('id', $ids)->pluck('id')->all(),
            'scopeFailed is a deprecated alias of invalid()');

        $this->assertSame('PROCESSING FAILED', $this->rows['blank_failed']->statusLabel());
        $this->assertSame('PROCESSING FAILED', $this->rows['pending_failed']->statusLabel());
        $this->assertSame('RECONCILED', $this->rows['valid_failed']->statusLabel());
        $this->assertSame('INVALID', $this->rows['invalid']->statusLabel());
        $this->assertSame('danger', $this->rows['invalid']->statusBadgeColor());
        $this->assertSame('warning', $this->rows['blank_failed']->statusBadgeColor());
        $this->assertSame('warning', $this->rows['pending_failed']->statusBadgeColor());
        $this->assertSame('success', $this->rows['valid']->statusBadgeColor());

        $this->assertTrue($this->rows['pending_failed']->isRetryable());
        $this->assertTrue($this->rows['blank_failed']->isRetryable());
        $this->assertFalse($this->rows['invalid']->isRetryable(), 'never retry a business-INVALID sale');
        $this->assertFalse($this->rows['valid_failed']->isRetryable());
        $this->assertFalse($this->rows['valid']->isRetryable());
    }

    public function test_status_label_matches_shared_expectation_table(): void
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/fixtures/transaction_status_labels.json')), true);
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $tx = new Transaction(['validation_status' => $case['validation_status'], 'job_status' => $case['job_status']]);
            $tx->validation_status = $case['validation_status'];
            $tx->job_status = $case['job_status'];
            $this->assertSame($case['label'], $tx->statusLabel(), json_encode($case));
        }
    }

    public function test_js_status_label_util_matches_shared_expectation_table(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed; run tests/js/transaction-status-labels.mjs manually.');
        }

        exec(escapeshellarg($node).' '.escapeshellarg(base_path('tests/js/transaction-status-labels.mjs')).' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_no_reference_to_removed_validation_status_failed_constant(): void
    {
        $offenders = [];
        foreach (['app', 'routes', 'resources'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'jsx', 'ts', 'tsx'], true)) {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'VALIDATION_STATUS_FAILED')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders);
        $this->assertFalse(defined(Transaction::class.'::VALIDATION_STATUS_FAILED'));
    }

    // --- Status endpoint -----------------------------------------------------------

    public function test_status_endpoint_reports_each_state_per_d1(): void
    {
        $this->seedStatusFixtures();
        $this->rows['error'] = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'validation_status' => 'ERROR',
            'job_status' => 'COMPLETED',
        ]);

        $expect = [
            'valid' => ['VALID', 'COMPLETED'],
            'invalid' => ['INVALID', 'COMPLETED'],
            'pending_failed' => ['PENDING', 'FAILED'],
            'blank_failed' => ['PENDING', 'FAILED'],
            'valid_failed' => ['VALID', 'FAILED'],
            'error' => ['INVALID', 'COMPLETED'],
        ];

        foreach ($expect as $key => [$validation, $job]) {
            $this->getJson('/api/transactions/'.$this->rows[$key]->id.'/status')
                ->assertOk()
                ->assertJsonPath('data.validation_status', $validation)
                ->assertJsonPath('data.job_status', $job);
        }
    }

    // --- Dashboard -------------------------------------------------------------------

    public function test_dashboard_failed_count_is_invalid_or_failed_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        try {
            $this->seedStatusFixtures(now()->subHour());
            $metrics = app(DashboardService::class)->getAdvancedMetrics([
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-10',
            ], $this->tenant->id);

            // INVALID, PENDING+FAILED, ''+FAILED; legacy VALID+FAILED is reconciled.
            $this->assertSame(3, $metrics['reconciliation']['failed']);
            $this->assertSame(3, $metrics['exceptions']['failed_reconciliations']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_tax_exceptions_count_invalid_and_processing_failed_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        try {
            $at = now()->subHour();
            $make = fn (string $validation, string $job) => Transaction::factory()->create([
                'tenant_id' => $this->tenant->id,
                'terminal_id' => $this->terminal->id,
                'transaction_timestamp' => $at,
                'validation_status' => $validation,
                'job_status' => $job,
                'last_error' => 'VAT mismatch on tax lines',
                'tax_exempt' => true,
            ]);
            $make('INVALID', 'COMPLETED');
            $make('PENDING', 'FAILED');
            $this->legacyBlank($make('PENDING', 'FAILED'));
            $make('VALID', 'FAILED');      // validated: not a tax exception
            $make('PENDING', 'QUEUED');    // still processing: not a tax exception

            $metrics = app(DashboardService::class)->getAdvancedMetrics([
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-10',
            ], $this->tenant->id);

            $this->assertSame(3, $metrics['exceptions']['invalid_tax_records']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_chart_exceptions_are_invalid_or_failed_job(): void
    {
        $this->seedStatusFixtures(Carbon::parse('2026-09-10 10:15:00'));

        $response = app(\App\Http\Controllers\DashboardController::class)->apiCharts(Request::create('/', 'GET', [
            'granularity' => 'hourly',
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-10',
            'tenant_id' => $this->tenant->id,
        ]));
        $data = $response->getData(true);
        $payload = $data['data'] ?? $data;

        $this->assertSame(3, $payload['exceptions'][10], 'legacy VALID+FAILED is not an exception');
        $this->assertSame(2, $payload['reconciled'][10]);
    }

    // --- Tenant ingestion audit ---------------------------------------------------------

    public function test_tenant_audit_splits_invalid_and_processing_failed_counts(): void
    {
        $this->seedStatusFixtures();

        Artisan::call('tsms:ingestion-audit', [
            '--tenant' => $this->tenant->id,
            '--from' => now()->subDay()->toDateTimeString(),
            '--to' => now()->addDay()->toDateTimeString(),
            '--json' => true,
        ]);
        $row = json_decode(Artisan::output(), true)['rows'][0];

        $this->assertSame($this->tenant->id, $row['tenant_id']);
        $this->assertSame(1, $row['invalid']);
        $this->assertSame(2, $row['processing_failed'], "PENDING+FAILED and ''+FAILED");
        $this->assertSame(3, $row['invalid_or_failed']);
        $this->assertSame(3, $row['job_failed'], 'job_failed still counts every FAILED job');
        // Gross/net stay unfiltered in the audit.
        $this->assertEquals(230.0, $row['gross_sales']);
    }

    // --- Notifications ---------------------------------------------------------------

    public function test_processing_failure_alert_is_separate_from_business_invalid_alert(): void
    {
        Notification::fake();
        config([
            'notifications.transaction_failure_threshold' => 5,
            'notifications.processing_failure_threshold' => 2,
            'notifications.processing_failure_time_window' => 60,
        ]);
        RateLimiter::clear('alerts:tx-processing-failure-threshold:'.$this->terminal->id);
        RateLimiter::clear('alerts:tx-failure-threshold:'.$this->terminal->id);
        $admin = $this->adminUser();

        $this->seedStatusFixtures();
        $service = new NotificationService;

        $service->checkTransactionFailureThresholds((string) $this->terminal->id);
        Notification::assertNotSentTo($admin, TransactionFailureThresholdExceeded::class);

        $service->checkProcessingFailureThresholds((string) $this->terminal->id);
        Notification::assertSentTo($admin, TransactionFailureThresholdExceeded::class, function ($notification) use ($admin) {
            $data = $notification->toDatabase($admin);

            return $notification->alertType() === TransactionFailureThresholdExceeded::TYPE_PROCESSING_FAILURE
                && $data['type'] === 'transaction_processing_failure_threshold_exceeded'
                && $data['threshold_data']['current_count'] === 2;
        });
    }

    public function test_business_invalid_alert_keeps_its_type_and_ignores_processing_failures(): void
    {
        Notification::fake();
        config(['notifications.transaction_failure_threshold' => 1]);
        RateLimiter::clear('alerts:tx-failure-threshold:'.$this->terminal->id);
        $admin = $this->adminUser();

        $this->seedStatusFixtures();
        (new NotificationService)->checkTransactionFailureThresholds((string) $this->terminal->id);

        Notification::assertSentTo($admin, TransactionFailureThresholdExceeded::class, function ($notification) use ($admin) {
            $data = $notification->toDatabase($admin);

            return $notification->alertType() === TransactionFailureThresholdExceeded::TYPE_BUSINESS_INVALID
                && $data['type'] === 'transaction_failure_threshold_exceeded'
                && $data['threshold_data']['current_count'] === 1;
        });
    }

    // --- Resource collection / detail service ------------------------------------------

    public function test_collection_summary_counts_invalid_and_processing_failed_separately(): void
    {
        $this->seedStatusFixtures();
        $page = Transaction::whereIn('id', $this->ownIds())->paginate(50);

        $summary = (new TransactionCollection($page))->toArray(Request::create('/'))['summary'];

        $this->assertSame(1, $summary['failed_transactions']);
        $this->assertSame(2, $summary['processing_failed_transactions']);
        $this->assertSame(1, $summary['pending_transactions']);
        $this->assertSame(2, $summary['valid_transactions']);
    }

    public function test_detail_service_error_frequency_counts_invalid_or_failed_job(): void
    {
        $this->seedStatusFixtures();
        $service = app(\App\Services\TransactionDetailService::class);
        $method = new \ReflectionMethod($service, 'calculateErrorFrequency');
        $method->setAccessible(true);

        $rows = $method->invoke($service, $this->terminal->id);

        $this->assertSame(5, (int) $rows->sum('total'));
        $this->assertSame(3, (int) $rows->sum('errors'), 'legacy VALID+FAILED is not an error');
    }

    // --- Transaction logs (filter + export) ----------------------------------------------

    public function test_log_list_supports_processing_failed_filter_and_returns_job_status(): void
    {
        $this->seedStatusFixtures(Carbon::parse('2026-09-10 10:15:00'));
        Sanctum::actingAs($this->adminUser());

        $response = $this->getJson('/api/transactions/logs?'.http_build_query([
            'status' => 'PROCESSING_FAILED',
            'tenant_id' => $this->tenant->id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-10',
        ]))->assertOk();

        $data = collect($response->json('data'));
        $this->assertEqualsCanonicalizing(
            [$this->rows['pending_failed']->id, $this->rows['blank_failed']->id],
            $data->pluck('id')->all()
        );
        $this->assertSame(['FAILED'], $data->pluck('job_status')->unique()->values()->all());

        $invalid = $this->getJson('/api/transactions/logs?'.http_build_query([
            'status' => 'INVALID',
            'tenant_id' => $this->tenant->id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-10',
        ]))->assertOk();
        $this->assertSame([$this->rows['invalid']->id], collect($invalid->json('data'))->pluck('id')->all());
    }

    public function test_log_summary_processing_failed_filter_totals_only_processing_failed_rows(): void
    {
        $this->seedStatusFixtures(Carbon::parse('2026-09-10 10:15:00'));
        Sanctum::actingAs($this->adminUser());

        $response = $this->getJson('/api/transactions/logs/summary?'.http_build_query([
            'status' => 'PROCESSING_FAILED',
            'tenant_id' => $this->tenant->id,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-10',
        ]))->assertOk();

        $this->assertEquals(60.0, (float) $response->json('grandTotal.raw_gross_sales'));
    }

    public function test_export_supports_processing_failed_filter(): void
    {
        $this->seedStatusFixtures();

        $ids = (new TransactionLogsExport(['status' => 'PROCESSING_FAILED', 'tenant_id' => $this->tenant->id]))
            ->query()->pluck('transactions.id')->all();
        $this->assertEqualsCanonicalizing([$this->rows['pending_failed']->id, $this->rows['blank_failed']->id], $ids);

        $invalidIds = (new TransactionLogsExport(['status' => 'INVALID', 'tenant_id' => $this->tenant->id]))
            ->query()->pluck('transactions.id')->all();
        $this->assertSame([$this->rows['invalid']->id], $invalidIds);
    }

    public function test_log_list_and_export_keep_plain_status_match_for_voided_and_refunded(): void
    {
        // Void handling is parked (ADR-004 §10 item 1): the list and export keep their
        // previous behaviour, a plain validation_status match for VOIDED/REFUNDED.
        $this->seedStatusFixtures(Carbon::parse('2026-09-10 10:15:00'));
        DB::table('transactions')->where('id', $this->rows['valid']->id)
            ->update(['voided_at' => '2026-09-10 10:20:00', 'is_refunded' => true]);
        Sanctum::actingAs($this->adminUser());

        foreach (['VOIDED', 'REFUNDED'] as $status) {
            // Same rows as the plain equality match used on main (whatever MySQL's enum
            // coercion makes of the out-of-enum literal); never the voided/refunded row.
            $plain = Transaction::withoutGlobalScopes()->whereIn('id', $this->ownIds())
                ->where('validation_status', $status)->pluck('id')->sort()->values()->all();

            $list = $this->getJson('/api/transactions/logs?'.http_build_query([
                'status' => $status,
                'tenant_id' => $this->tenant->id,
                'date_from' => '2026-09-10',
                'date_to' => '2026-09-10',
            ]))->assertOk();
            $listed = collect($list->json('data'))->pluck('id')->sort()->values()->all();
            $this->assertSame($plain, $listed, "list {$status}");
            $this->assertNotContains($this->rows['valid']->id, $listed);

            $exported = (new TransactionLogsExport(['status' => $status, 'tenant_id' => $this->tenant->id]))
                ->query()->whereIn('transactions.id', $this->ownIds())->pluck('transactions.id')->sort()->values()->all();
            $this->assertSame($plain, $exported, "export {$status}");
            $this->assertNotContains($this->rows['valid']->id, $exported);
        }
    }

    // --- Blade views -------------------------------------------------------------------

    public function test_log_detail_view_shows_processing_failed_badge_and_retry_only_when_retryable(): void
    {
        $this->seedStatusFixtures();
        \Illuminate\Support\Facades\Gate::define('retry-transactions', fn () => true);
        $this->actingAs($this->adminUser());

        $detail = app(\App\Services\TransactionDetailService::class);
        $render = fn (Transaction $tx) => view('transactions.logs.show', [
            'transaction' => $tx->load(['terminal', 'jobs', 'validations']),
            'metrics' => $detail->getDetailedMetrics($tx),
            'timeline' => $detail->getProcessingTimeline($tx),
        ])->render();

        $blank = $render($this->rows['blank_failed']);
        $this->assertStringContainsString('PROCESSING FAILED', $blank);
        $this->assertStringContainsString('id="retryBtn"', $blank);

        $invalid = $render($this->rows['invalid']);
        $this->assertStringContainsString('bg-danger-subtle', $invalid);
        $this->assertStringNotContainsString('id="retryBtn"', $invalid);

        $this->assertStringNotContainsString('id="retryBtn"', $render($this->rows['valid_failed']));
    }
}
