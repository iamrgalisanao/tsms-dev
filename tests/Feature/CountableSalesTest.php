<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\Reports\FinanceCalculationService;
use App\Services\Reports\HourlyReportService;
use App\Support\Reporting\CountableSales;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ADR-004 D10 (slice 3): gross/net sales sums exclude business-INVALID rows; PENDING and
 * legacy '' rows still count. Voids stay excluded only where they were excluded before.
 *
 * Fixture (one tenant, one business day): VALID 100, PENDING 50, INVALID 30,
 * voided VALID 20, legacy '' 10.
 *   - paths that already exclude voids: 160
 *   - paths that did not exclude voids (dashboard KPIs, transactions_hourly/daily): 180
 * A second tenant holds an INVALID 999 and a VALID 7 row to prove tenant scoping.
 * Rows can leak between tests, so every assertion is tenant-scoped.
 */
class CountableSalesTest extends TestCase
{
    private Tenant $tenant;

    private PosTerminal $terminal;

    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['trade_name' => 'Countable Tenant']);
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->otherTenant = Tenant::factory()->create(['trade_name' => 'Other Countable Tenant']);
    }

    private function seedFixture(string $timestamp): void
    {
        $make = function (string $validation, float $amount, array $extra = [], ?Tenant $tenant = null, ?PosTerminal $terminal = null) use ($timestamp) {
            $tenant ??= $this->tenant;
            $terminal ??= $this->terminal;

            return Transaction::factory()->create(array_merge([
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'transaction_timestamp' => $timestamp,
                'created_at' => $timestamp,
                'gross_sales' => $amount,
                'net_sales' => $amount,
                'vatable_sales' => 0,
                'vat_amount' => 0,
                'validation_status' => $validation,
                'job_status' => 'COMPLETED',
            ], $extra));
        };

        $make('VALID', 100);
        $make('PENDING', 50, ['job_status' => 'QUEUED']);
        $make('INVALID', 30);
        $make('VALID', 20, ['voided_at' => $timestamp, 'void_reason' => 'test void']);
        $blank = $make('PENDING', 10, ['job_status' => 'FAILED']);

        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::table('transactions')->where('id', $blank->id)->update(['validation_status' => 'FAILED']); // stored as ''
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
        $this->assertSame('', DB::table('transactions')->where('id', $blank->id)->value('validation_status'));

        $otherTerminal = PosTerminal::factory()->create(['tenant_id' => $this->otherTenant->id]);
        $make('INVALID', 999, [], $this->otherTenant, $otherTerminal);
        $make('VALID', 7, [], $this->otherTenant, $otherTerminal);
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_helper_apply_and_sum_expression(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');

        $applied = CountableSales::apply(DB::table('transactions')->where('tenant_id', $this->tenant->id));
        $this->assertEquals(160.0, (float) $applied->sum('gross_sales'));

        $this->assertEquals(160.0, (float) Transaction::withoutGlobalScopes()->countableSales()
            ->where('tenant_id', $this->tenant->id)->sum('gross_sales'));

        $conditional = DB::table('transactions')->where('tenant_id', $this->tenant->id)
            ->selectRaw(CountableSales::sumExpr('gross_sales').' as gross, COUNT(*) as n')->first();
        $this->assertEquals(180.0, (float) $conditional->gross, 'sumExpr drops INVALID only');
        $this->assertSame(5, (int) $conditional->n, 'row counts stay visible');

        config(['tsms.reporting.exclude_voids_from_totals' => false]);
        $this->assertEquals(180.0, (float) CountableSales::apply(DB::table('transactions')->where('tenant_id', $this->tenant->id))
            ->sum('gross_sales'), 'void exclusion keeps following the existing config flag');
    }

    public function test_finance_report_raw_path_excludes_invalid_and_voids(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');
        Sanctum::actingAs($this->user('admin'));

        $response = $this->getJson('/reports/data?month=2026-06&tenant='.$this->tenant->id)->assertOk();

        $this->assertSame('raw_transactions', $response->json('source'));
        $this->assertEquals(160.0, (float) $response->json('totals.gross_sales'));
        $this->assertSame(3, (int) $response->json('totals.transaction_count'));
    }

    public function test_daily_summary_builder_and_summary_read_path_exclude_invalid(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');

        $this->artisan('reports:refresh-daily-transaction-summaries', [
            '--from' => '2026-06-15',
            '--to' => '2026-06-15',
            '--tenant' => $this->tenant->id,
        ])->assertExitCode(0);

        $summary = DB::table('daily_transaction_summaries')
            ->where('tenant_id', $this->tenant->id)
            ->where('business_date', '2026-06-15')
            ->selectRaw('SUM(gross_sales) as gross, SUM(net_sales) as net, SUM(transaction_count) as n')
            ->first();
        $this->assertEquals(160.0, (float) $summary->gross);
        $this->assertEquals(160.0, (float) $summary->net);
        $this->assertSame(3, (int) $summary->n);

        // Mark the whole month refreshed so the report reads the summary table.
        foreach (range(1, 30) as $day) {
            DB::table('report_refresh_states')->updateOrInsert([
                'report_type' => 'daily_transaction_summaries',
                'tenant_id' => $this->tenant->id,
                'business_date' => sprintf('2026-06-%02d', $day),
            ], ['status' => 'completed', 'refreshed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        Sanctum::actingAs($this->user('admin'));
        $response = $this->getJson('/reports/data?month=2026-06&tenant='.$this->tenant->id)->assertOk();

        $this->assertSame('daily_transaction_summaries', $response->json('source'));
        $this->assertEquals(160.0, (float) $response->json('totals.gross_sales'));
    }

    public function test_hourly_report_service_excludes_invalid_and_voids(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');

        $rows = app(HourlyReportService::class)->getHourlyAggregates('2026-06-15', '2026-06-15', (string) $this->tenant->id);

        $this->assertEquals(160.0, (float) collect($rows)->sum('gross_sales'));
        $this->assertSame(3, (int) collect($rows)->sum('transaction_count'));
    }

    public function test_commercial_tenant_breakdown_excludes_invalid_and_voids(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');
        $this->actingAs($this->user('commercial'));

        $response = $this->getJson('/commercial/reports/transactions/weekly?date_from=2026-06-15&date_to=2026-06-15')->assertOk();
        $rows = collect($response->json('days'))->where('tenant_id', $this->tenant->id);

        $this->assertEquals(160.0, (float) $rows->sum('gross_sales'));
        $this->assertEquals(7.0, (float) collect($response->json('days'))->where('tenant_id', $this->otherTenant->id)->sum('gross_sales'));
    }

    public function test_finance_calculation_skips_invalid_in_memory(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');
        $transactions = Transaction::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->get();

        $components = app(FinanceCalculationService::class)->aggregateComponents($transactions);

        $this->assertEquals(160.0, $components['gross_sales']);
        $this->assertEquals(160.0, $components['net_sales']);
    }

    public function test_dashboard_kpis_exclude_invalid_but_not_voids(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        try {
            $this->seedFixture('2026-06-15 09:01:00');

            $metrics = app(DashboardService::class)->getAdvancedMetrics([
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-15',
            ], $this->tenant->id);

            $this->assertEquals(180.0, $metrics['total_sales']['current']);
            $this->assertEquals(180.0, $metrics['total_net_sales']['current']);
            $this->assertSame(5, $metrics['total_transactions']['current']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_charts_exclude_invalid_but_not_voids(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');

        $data = app(DashboardController::class)->apiCharts(Request::create('/', 'GET', [
            'granularity' => 'hourly',
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-15',
            'tenant_id' => $this->tenant->id,
        ]))->getData(true);

        $this->assertEquals(180.0, (float) $data['sales'][9]);
        $this->assertEquals(180.0, (float) $data['net_sales'][9]);
        $this->assertSame(5, $data['volume'][9]);
        $this->assertEquals(180.0, (float) collect($data['top_tenants'])->firstWhere('id', $this->tenant->id)['gross_sales']);
    }

    public function test_transaction_log_totals_exclude_invalid_unless_filtered(): void
    {
        $this->seedFixture('2026-06-15 09:01:00');
        Sanctum::actingAs($this->user('admin'));
        $query = fn (array $extra = []) => '/api/transactions/logs/summary?'.http_build_query(array_merge([
            'tenant_id' => $this->tenant->id,
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-15',
        ], $extra));

        $all = $this->getJson($query())->assertOk();
        $this->assertEquals(160.0, (float) $all->json('grandTotal.raw_gross_sales'));

        $invalid = $this->getJson($query(['status' => 'INVALID']))->assertOk();
        $this->assertEquals(30.0, (float) $invalid->json('grandTotal.raw_gross_sales'),
            'an explicit INVALID filter still shows INVALID totals');
    }

    public function test_reporting_refresh_sums_exclude_invalid_and_issues_track_invalid(): void
    {
        $at = now()->subMinutes(5)->format('Y-m-d H:i:s');
        $this->seedFixture($at);
        // Extra INVALID rows at both extremes: they must not drive the row statistics.
        foreach ([5, 500] as $amount) {
            Transaction::factory()->create([
                'tenant_id' => $this->tenant->id,
                'terminal_id' => $this->terminal->id,
                'transaction_timestamp' => $at,
                'gross_sales' => $amount,
                'net_sales' => $amount,
                'validation_status' => 'INVALID',
                'job_status' => 'COMPLETED',
            ]);
        }

        $this->artisan('reporting:refresh', ['tableArg' => 'transactions_hourly', '--hours' => 3])->assertExitCode(0);

        $hourly = DB::table('transactions_hourly')->where('tenant_id', $this->tenant->id)
            ->selectRaw('SUM(tx_count) as n, SUM(total_amount) as total, SUM(total_gross_amount) as gross, SUM(total_net_amount) as net, SUM(issues_count) as issues, SUM(issues_amount) as issues_amount, MAX(avg_amount) as avg, MIN(min_amount) as min_amount, MAX(max_amount) as max_amount')
            ->first();
        $this->assertSame(7, (int) $hourly->n);
        $this->assertEquals(180.0, (float) $hourly->total);
        $this->assertEquals(180.0, (float) $hourly->gross);
        $this->assertEquals(180.0, (float) $hourly->net);
        $this->assertEquals(45.0, (float) $hourly->avg, 'average over the 4 countable rows');
        $this->assertEquals(10.0, (float) $hourly->min_amount, 'row statistics exclude INVALID (the 5 and 500 rows)');
        $this->assertEquals(100.0, (float) $hourly->max_amount);
        $this->assertSame(3, (int) $hourly->issues);
        $this->assertEquals(535.0, (float) $hourly->issues_amount);

        $this->artisan('reporting:refresh', ['tableArg' => 'transactions_daily'])->assertExitCode(0);

        $daily = DB::table('transactions_daily')->where('tenant_id', $this->tenant->id)
            ->selectRaw('SUM(tx_count) as n, SUM(total_amount) as total, SUM(issues_count) as issues, SUM(issues_amount) as issues_amount')
            ->first();
        $this->assertSame(7, (int) $daily->n);
        $this->assertEquals(180.0, (float) $daily->total);
        $this->assertSame(3, (int) $daily->issues);
        $this->assertEquals(535.0, (float) $daily->issues_amount);
    }

    public function test_option_a_figures_count_valid_and_pending_but_not_invalid(): void
    {
        // ADR-004 §10 item 2, option A. None of these figures filtered voids before, and
        // void handling is parked, so the voided VALID 20 still counts: 100 + 50 + 10 + 20.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
        try {
            $this->seedFixture('2026-06-15 09:01:00');

            $metrics = app(DashboardService::class)->getAdvancedMetrics([
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-15',
            ], $this->tenant->id);

            $this->assertSame([[
                'trade_name' => 'Countable Tenant',
                'total_revenue' => 180.0,
            ]], $metrics['top_tenants']);
            $this->assertEquals(180.0, $metrics['revenue_composition']['net_sales']);

            // CommercialReportsController month/YTD now sum through CountableSales::sum().
            // The endpoint itself cannot be exercised: PosTerminal::transactions() is
            // hasMany(Transaction::class) with the default pos_terminal_id key, a column that
            // does not exist, so the controller's try/catch has always returned 0 (pre-existing,
            // reported separately). Pin the helper on the equivalent terminal-scoped query.
            $monthQuery = Transaction::withoutGlobalScopes()->where('terminal_id', $this->terminal->id)
                ->whereBetween('transaction_timestamp', [now()->startOfMonth(), now()->endOfMonth()]);
            $this->assertEquals(180.0, CountableSales::sum($monthQuery, 'gross_sales', 'transactions'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tenant_profile_annual_sales_counts_valid_and_pending_but_not_invalid(): void
    {
        // Formerly a raw ->sum('gross_sales') inside the Blade view, with no void filter;
        // void handling is parked, so the voided VALID 20 still counts: 100 + 50 + 10 + 20.
        // The Blade page itself cannot render in tests (pre-existing: its layout links the
        // undefined route commercial.sales-report.hourly), so read the view data directly.
        $this->seedFixture('2026-06-15 09:01:00');
        $controller = app(\App\Http\Controllers\Reports\CommercialReportsController::class);
        $annual = fn (int $year) => $controller
            ->tenantShow(Request::create('/commercial/reports/tenants/'.$this->tenant->id, 'GET', ['year' => $year]), $this->tenant->id)
            ->getData()['annual_sales'];

        $this->assertSame(180.0, $annual(2026));
        $this->assertSame(0.0, $annual(2025));
    }

    /**
     * Raw gross/net sums outside CountableSales, with the reason each is allowed.
     * Keyed by path relative to the project root; the value is [expected occurrence count, reason].
     */
    private const RAW_SALES_SUM_ALLOWLIST = [
        'app/Http/Controllers/TransactionLogController.php' => [4, 'Log totals: the row set is restricted by CountableSales::apply() when no status filter is set; an explicit status filter (e.g. INVALID) must show that status\'s own totals.'],
        'app/Services/Reports/SalesReportDataService.php' => [2, 'Reads daily_transaction_summaries, which RefreshDailyTransactionSummaries already builds from countable sales (no validation_status column there).'],
        'app/Services/TenantIngestionAuditService.php' => [2, 'Ingestion reconciliation audit: gross/net of everything ingested, intentionally unfiltered.'],
        'app/Http/Resources/TransactionCollection.php' => [2, 'Page totals of exactly the rows listed in the collection (mirrors the list filter).'],
        'app/Console/Commands/ReportingRefreshCommand.php' => [1, 'issues_amount: the gross of INVALID rows only (repurposed issues_* columns), the complement of the countable sum.'],
        'app/Models/Tenant.php' => [1, 'getDailySalesTotal(): dead daily-limit check (ADR-004 §10 item 3), left unchanged for the dead-code cleanup.'],
        'app/Models/Store.php' => [1, 'getDailySalesTotal(): dead daily-limit check like Tenant (wouldExceedDailyLimit has no callers), left for the dead-code cleanup.'],
    ];

    private const RAW_SALES_SUM_PATTERNS = [
        // SUM(gross_sales), SUM(t.net_sales), SUM(`t`.`gross_sales`), SUM(COALESCE(..)), SUM(IFNULL(..))
        '/\bSUM\(\s*(?:(?:COALESCE|IFNULL)\(\s*)?`?(?:\w+`?\.`?)?(?:gross_sales|net_sales)\b/i',
        // SUM(CASE ... gross_sales ... END)
        '/\bSUM\(\s*CASE\b(?:(?!\bEND\b)[\s\S]){0,400}?\b(?:gross_sales|net_sales)\b/i',
        // ->sum('gross_sales'), ->sum("t.net_sales"), ->sum('`t`.`gross_sales`')
        '/->sum\(\s*[\'"]`?(?:\w+`?\.`?)?(?:gross_sales|net_sales)`?[\'"]/i',
        // withSum('transactions', 'gross_sales') / withSum([...], 'net_sales')
        '/\bwithSum\([^;]*?[\'"](?:gross_sales|net_sales)[\'"]/i',
    ];

    private static function countRawSalesSums(string $source): int
    {
        $count = 0;
        foreach (self::RAW_SALES_SUM_PATTERNS as $pattern) {
            $count += preg_match_all($pattern, $source);
        }

        return $count;
    }

    public function test_raw_sales_sum_patterns_catch_known_shapes(): void
    {
        $caught = [
            'SUM(gross_sales)', 'sum( net_sales )', 'SUM(t.gross_sales)', 'SUM(`t`.`net_sales`)',
            'COALESCE(SUM(transactions.gross_sales), 0)', 'SUM(COALESCE(net_sales, 0))', 'SUM(IFNULL(t.gross_sales,0))',
            "SUM(CASE WHEN validation_status = 'VALID' THEN gross_sales ELSE 0 END)",
            "SUM(CASE WHEN x = 1\n THEN t.net_sales ELSE 0 END)",
            "->sum('gross_sales')", '->sum("net_sales")', "->sum('t.gross_sales')", "->sum('`t`.`net_sales`')",
            "->withSum('transactions', 'gross_sales')", "->withSum(['transactions as s' => fn (\$q) => \$q], 'net_sales')",
        ];
        foreach ($caught as $sample) {
            $this->assertSame(1, self::countRawSalesSums($sample), "must catch: {$sample}");
        }

        $ignored = [
            'SUM(vat_amount)', "->sum('vatable_sales')", 'MIN(gross_sales)', 'AVG(gross_sales)',
            'SUM(CASE WHEN a THEN 1 ELSE 0 END) AS n, gross_sales',
            "CountableSales::sumExpr('gross_sales')", "CountableSales::sum(\$query, 'net_sales')",
        ];
        foreach ($ignored as $sample) {
            $this->assertSame(0, self::countRawSalesSums($sample), "must ignore: {$sample}");
        }
    }

    public function test_no_raw_sales_sums_outside_countable_sales_helper(): void
    {
        $found = [];
        foreach (['app', 'resources/views'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                if ($relative === 'app/Support/Reporting/CountableSales.php') {
                    continue;
                }
                $count = self::countRawSalesSums((string) file_get_contents($file->getPathname()));
                if ($count > 0) {
                    $found[$relative] = $count;
                }
            }
        }
        ksort($found);

        $allowed = array_map(fn (array $entry) => $entry[0], self::RAW_SALES_SUM_ALLOWLIST);
        ksort($allowed);

        $this->assertSame($allowed, $found,
            'Sales sums must go through App\\Support\\Reporting\\CountableSales (ADR-004 D10 option A), or be added to the allowlist with a reason.');
    }
}
