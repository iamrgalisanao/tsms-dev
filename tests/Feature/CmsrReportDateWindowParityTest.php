<?php

namespace Tests\Feature;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Reporting\CountableSales;
use App\Traits\ResolvesReportBusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The CMSR queries add an index-friendly timestamp window in front of the exact
 * business-date CASE expression. These tests pin month-boundary rows of every
 * timestamp classification and prove the window never changes which rows count.
 */
class CmsrReportDateWindowParityTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTimezone;

    private User $adminUser;

    private Tenant $tenant;

    private PosTerminal $terminal;

    private PosTerminal $legacyTerminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Manila');
        config([
            'app.timezone' => 'Asia/Manila',
            'tsms.transaction_logs.timezone' => 'Asia/Manila',
        ]);

        $this->tenant = Tenant::factory()->create(['trade_name' => 'Window Tenant']);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'provider_id' => PosProvider::factory()->create(['timestamp_mode' => 'utc'])->id,
        ]);
        $this->legacyTerminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'provider_id' => PosProvider::factory()->create(['timestamp_mode' => 'local_time_with_z'])->id,
        ]);

        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        }

        $this->adminUser = User::factory()->create();
        if (method_exists($this->adminUser, 'assignRole')) {
            $this->adminUser->assignRole('admin');
        }

        $this->seedBoundaryRows();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_raw_path_buckets_boundary_rows_exactly_like_the_unwindowed_expression(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-10&tenant='.$this->tenant->id);

        $response->assertOk();
        $this->assertSame('raw_transactions', $response->json('source'));
        $this->assertReportMatchesExpectation($response->json());
    }

    public function test_summary_refresh_and_summary_path_bucket_boundary_rows_identically(): void
    {
        $this->artisan('reports:refresh-daily-transaction-summaries', [
            '--from' => '2026-10-01',
            '--to' => '2026-10-31',
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        $summaryGross = DB::table('daily_transaction_summaries')
            ->where('tenant_id', $this->tenant->id)
            ->groupBy('business_date')
            ->selectRaw('business_date, SUM(gross_sales) as gross')
            ->pluck('gross', 'business_date')
            ->map(fn ($value) => (float) $value)
            ->all();
        $this->assertEquals($this->unwindowedGrossByDate('2026-10-01', '2026-10-31', $this->tenant->id), $summaryGross);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-10&tenant='.$this->tenant->id);

        $response->assertOk();
        $this->assertSame('daily_transaction_summaries', $response->json('source'));
        $this->assertReportMatchesExpectation($response->json());
    }

    public function test_all_tenants_scope_matches_the_unwindowed_expression(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-10&tenant=all');

        $response->assertOk();
        // Other tests' rows can survive in this DB (known isolation bug), so compare to the
        // unscoped oracle rather than this tenant's fixed figures.
        $expected = $this->unwindowedGrossByDate('2026-10-01', '2026-10-31', null);
        $this->assertArrayHasKey('2026-10-31', $expected);
        $actual = collect($response->json('daily_totals'))->map(fn ($day) => (float) $day['gross_sales'])->all();
        ksort($actual);
        $this->assertEquals($expected, $actual);
    }

    public function test_every_business_date_filter_is_preceded_by_the_timestamp_window(): void
    {
        Sanctum::actingAs($this->adminUser);
        DB::enableQueryLog();

        $this->getJson('/reports/data?month=2026-10&tenant='.$this->tenant->id)->assertOk();

        $dateFiltered = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'JSON_VALID') && str_contains($sql, ' BETWEEN ? AND ?'));
        DB::disableQueryLog();

        // main aggregate, adjustments, taxes, payload adjustments
        $this->assertCount(4, $dateFiltered);
        foreach ($dateFiltered as $sql) {
            $this->assertMatchesRegularExpression('/`?transaction_timestamp`? >= \?/', $sql);
            $this->assertMatchesRegularExpression('/`?created_at`? < \?/', $sql);
        }

        // Adjustment/tax rows join to a materialized per-transaction date, not the raw CASE.
        $childQueries = $dateFiltered->filter(fn (string $sql) => str_contains($sql, 'transaction_adjustments') || str_contains($sql, 'transaction_taxes'));
        $this->assertCount(2, $childQueries);
        foreach ($childQueries as $sql) {
            $this->assertStringContainsString('/*+ NO_MERGE(tx_dates) */', $sql);
        }
    }

    public function test_summary_path_adjustments_use_per_transaction_dates(): void
    {
        $this->artisan('reports:refresh-daily-transaction-summaries', [
            '--from' => '2026-10-01',
            '--to' => '2026-10-31',
            '--tenant' => $this->tenant->id,
        ])->assertSuccessful();

        Sanctum::actingAs($this->adminUser);
        DB::enableQueryLog();

        $this->getJson('/reports/data?month=2026-10&tenant='.$this->tenant->id)
            ->assertOk()
            ->assertJsonPath('source', 'daily_transaction_summaries');

        $adjustmentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'transaction_adjustments') && str_contains($sql, 'JSON_VALID'));
        DB::disableQueryLog();

        $this->assertCount(1, $adjustmentQueries);
        $this->assertStringContainsString('/*+ NO_MERGE(tx_dates) */', $adjustmentQueries->first());
    }

    private function assertReportMatchesExpectation(array $report): void
    {
        $expected = [
            '2026-10-01' => 1.0 + 32.0,  // UTC row shifted forward past midnight + NULL-timestamp created_at fallback
            '2026-10-15' => 64.0,
            '2026-10-31' => 4.0 + 16.0,  // plain-local payload + legacy local_time_with_z row
        ];

        $this->assertEquals($expected, $this->unwindowedGrossByDate('2026-10-01', '2026-10-31', $this->tenant->id));

        $actual = collect($report['daily_totals'])->map(fn ($day) => (float) $day['gross_sales'])->all();
        ksort($actual);
        $this->assertEquals($expected, $actual);
        $this->assertEquals(array_sum($expected), (float) $report['totals']['gross_sales']);

        // Payload- and adjustment-derived components follow the same rows.
        // senior = max(column 0, adjustment table 3, payload 5); the out-of-month 100 must not leak in.
        $this->assertEquals(5.0, (float) $report['daily_totals']['2026-10-01']['senior_discount']);
        $this->assertEquals(6.0, (float) $report['daily_totals']['2026-10-31']['other_tax']);
        // pwd = SUM of both adjustment-table rows (no column/payload value); the INVALID row's 1000 is excluded.
        $this->assertEquals(2.0 + 4.0, (float) $report['daily_totals']['2026-10-15']['pwd_discount']);
    }

    /**
     * Oracle: the business-date expression alone, with no timestamp window.
     */
    private function unwindowedGrossByDate(string $startDate, string $endDate, ?int $tenantId): array
    {
        $resolver = new class
        {
            use ResolvesReportBusinessDate;

            public function expression(): string
            {
                return $this->reportDateExpression(
                    'COALESCE(transactions.transaction_timestamp, transactions.created_at)',
                    'transactions.original_payload',
                    'pp.timestamp_mode',
                    'transactions.transaction_timestamp'
                );
            }
        };
        $expr = $resolver->expression();

        $query = DB::table('transactions')
            ->leftJoin('pos_terminals as pt', 'pt.id', '=', 'transactions.terminal_id')
            ->leftJoin('pos_providers as pp', 'pp.id', '=', 'pt.provider_id')
            ->selectRaw("{$expr} as report_date, SUM(transactions.gross_sales) as gross")
            ->whereRaw("{$expr} BETWEEN ? AND ?", [$startDate, $endDate])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('report_date')
            ->orderBy('report_date');

        CountableSales::apply($query, 'transactions');

        return $query->pluck('gross', 'report_date')->map(fn ($value) => (float) $value)->all();
    }

    private function seedBoundaryRows(): void
    {
        // Sep 30 16:30 UTC => Oct 1 00:30 Manila (in); carries payload + table senior discounts.
        $inside = $this->createRow($this->terminal, '2026-09-30 16:30:00', '2026-09-30T16:30:00Z', 1.00, [
            ['adjustment_type' => 'senior_discount', 'amount' => '5.00'],
        ]);
        DB::table('transaction_adjustments')->insert([
            'transaction_pk' => $inside->id, 'adjustment_type' => 'senior_discount', 'amount' => 3.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Sep 30 15:59 UTC => Sep 30 23:59 Manila (out).
        $this->createRow($this->terminal, '2026-09-30 15:59:00', '2026-09-30T15:59:00Z', 2.00, [
            ['adjustment_type' => 'senior_discount', 'amount' => '100.00'],
        ]);
        // Plain local payload: stored wall clock is the business date (in, Oct 31).
        $plainLocal = $this->createRow($this->terminal, '2026-10-31 23:30:00', '2026-10-31 23:30:00', 4.00);
        DB::table('transaction_taxes')->insert([
            'transaction_pk' => $plainLocal->id, 'tax_type' => 'AMUSEMENT_TAX', 'amount' => 6.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Oct 31 16:30 UTC => Nov 1 00:30 Manila (out); its tax must not leak into Oct 31.
        $nextMonth = $this->createRow($this->terminal, '2026-10-31 16:30:00', '2026-10-31T16:30:00Z', 8.00);
        DB::table('transaction_taxes')->insert([
            'transaction_pk' => $nextMonth->id, 'tax_type' => 'AMUSEMENT_TAX', 'amount' => 50.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Legacy row, no payload, local_time_with_z provider: not shifted (in, Oct 31).
        $this->createRow($this->legacyTerminal, '2026-10-31 20:00:00', null, 16.00);
        // No transaction_timestamp: created_at Sep 30 17:00 UTC-shifted => Oct 1 01:00 (in).
        $nullTimestamp = $this->createRow($this->terminal, '2026-10-20 12:00:00', null, 32.00);
        DB::table('transactions')->where('id', $nullTimestamp->id)->update([
            'transaction_timestamp' => null,
            'created_at' => '2026-09-30 17:00:00',
        ]);
        // Mid-month, no payload (in, Oct 15); only the adjustment table carries its PWD discount.
        $midMonth = $this->createRow($this->terminal, '2026-10-15 04:00:00', null, 64.00);
        foreach ([2.00, 4.00] as $amount) {
            DB::table('transaction_adjustments')->insert([
                'transaction_pk' => $midMonth->id, 'adjustment_type' => 'pwd_discount', 'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Same day but INVALID: neither its sale nor its PWD adjustment may count.
        $invalid = $this->createRow($this->terminal, '2026-10-15 05:00:00', null, 512.00);
        DB::table('transactions')->where('id', $invalid->id)->update(['validation_status' => 'INVALID']);
        DB::table('transaction_adjustments')->insert([
            'transaction_pk' => $invalid->id, 'adjustment_type' => 'pwd_discount', 'amount' => 1000.00,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Clearly outside the month.
        $this->createRow($this->terminal, '2026-08-15 04:00:00', null, 128.00);
        $this->createRow($this->terminal, '2026-09-29 20:00:00', '2026-09-29T20:00:00Z', 256.00);
    }

    private function createRow(PosTerminal $terminal, string $timestamp, ?string $payloadTimestamp, float $gross, array $adjustments = []): Transaction
    {
        $payload = null;
        if ($payloadTimestamp !== null) {
            $payload = ['transaction_timestamp' => $payloadTimestamp];
            if ($adjustments !== []) {
                $payload['adjustments'] = $adjustments;
            }
        }

        return Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => $timestamp,
            'gross_sales' => $gross,
            'net_sales' => $gross,
            'vatable_sales' => $gross,
            'vat_amount' => 0,
            'sc_vat_exempt_sales' => 0,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'validation_status' => 'VALID',
            'original_payload' => $payload === null ? null : json_encode($payload),
        ]);
    }
}
