<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use App\Exports\TransactionLogsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use App\Services\TransactionLogService;

class TransactionLogTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $adminUser;
    protected $terminal;
    protected $tenant;

    public function setUp(): void
    {
        parent::setUp();

        // Create a tenant
        $this->tenant = Tenant::factory()->create([
            'trade_name' => 'Test Tenant',
            'status' => 'active',
        ]);

        // Create user
        $this->user = User::factory()->create([
            'name' => 'Regular User',
        ]);

        // Ensure admin role exists
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            \Spatie\Permission\Models\Role::firstOrCreate([
                'name' => 'admin',
                'guard_name' => 'web',
            ]);
        }

        // Create admin user
        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
        ]);

        if (method_exists($this->adminUser, 'assignRole')) {
            $this->adminUser->assignRole('admin');
        }

        // Create terminal
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status_id' => 1, // active
        ]);
    }

    /** @test */
    public function test_it_displays_receipt_no_on_the_transaction_logs_page()
    {
        // Create a transaction with a distinct receipt number
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-TEST-998877',
            'gross_sales' => 1500.00,
            'net_sales' => 1400.00,
        ]);

        // Access the transaction logs page
        $response = $this->actingAs($this->adminUser)
            ->get('/api/transactions/logs');

        $response->assertStatus(200);

        // Verify that receipt number column header and the receipt number itself are rendered in the HTML
        $response->assertSee('Receipt No');
        $response->assertSee('REC-TEST-998877');
    }

    /** @test */
    public function test_it_returns_receipt_no_in_transaction_json_response()
    {
        // Create a transaction
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-JSON-123456',
            'gross_sales' => 1500.00,
            'net_sales' => 1400.00,
        ]);

        // Request JSON response
        $response = $this->actingAs($this->adminUser)
            ->getJson(route('transactions.logs.show', $transaction->id));

        $response->assertStatus(200);

        // Verify that receipt_no is returned correctly
        $response->assertJsonFragment([
            'receipt_no' => 'REC-JSON-123456',
        ]);
    }

    public function test_tenant_bound_users_cannot_read_other_tenants_transaction_logs()
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $otherTenant = Tenant::factory()->create(['trade_name' => 'Other Tenant']);
        $otherTerminal = PosTerminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status_id' => 1,
        ]);

        $ownTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-OWN-TENANT',
        ]);
        $otherTransaction = Transaction::factory()->create([
            'tenant_id' => $otherTenant->id,
            'terminal_id' => $otherTerminal->id,
            'transaction_id' => 'TXN-OTHER-TENANT',
        ]);

        $financeUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $financeUser->assignRole('finance');

        Sanctum::actingAs($financeUser);

        $response = $this->getJson('/api/transactions/logs?tenant_id=' . $otherTenant->id);

        $response->assertOk();
        $transactionIds = collect($response->json('data'))->pluck('transaction_id');
        $this->assertTrue($transactionIds->contains($ownTransaction->transaction_id));
        $this->assertFalse($transactionIds->contains($otherTransaction->transaction_id));

        $this->getJson('/api/transactions/logs/' . $otherTransaction->id)
            ->assertNotFound();
    }

    public function test_transaction_log_updates_cache_is_namespaced_by_tenant(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $tenantA = $this->tenant;
        $tenantB = Tenant::factory()->create(['trade_name' => 'Tenant B']);
        $terminalB = PosTerminal::factory()->create([
            'tenant_id' => $tenantB->id,
            'status_id' => 1,
        ]);

        $tenantATransaction = Transaction::factory()->create([
            'tenant_id' => $tenantA->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-UPDATES-TENANT-A',
        ]);
        $tenantBTransaction = Transaction::factory()->create([
            'tenant_id' => $tenantB->id,
            'terminal_id' => $terminalB->id,
            'transaction_id' => 'TXN-UPDATES-TENANT-B',
        ]);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA->assignRole('finance');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userB->assignRole('finance');

        Cache::flush();
        $this->withTenantScopeEnabledForTest(function () use ($userA, $userB, $tenantATransaction, $tenantBTransaction) {
            $service = app(TransactionLogService::class);

            $this->actingAs($userA);
            $tenantAFirst = $service->getUpdatesAfter(0)->pluck('transaction_id');
            $this->assertTrue($tenantAFirst->contains($tenantATransaction->transaction_id));
            $this->assertFalse($tenantAFirst->contains($tenantBTransaction->transaction_id));

            $this->actingAs($userB);
            $tenantBSecond = $service->getUpdatesAfter(0)->pluck('transaction_id');
            $this->assertTrue($tenantBSecond->contains($tenantBTransaction->transaction_id));
            $this->assertFalse($tenantBSecond->contains($tenantATransaction->transaction_id));
        });

        Cache::flush();
        $this->withTenantScopeEnabledForTest(function () use ($userA, $userB, $tenantATransaction, $tenantBTransaction) {
            $service = app(TransactionLogService::class);

            $this->actingAs($userB);
            $tenantBFirst = $service->getUpdatesAfter(0)->pluck('transaction_id');
            $this->assertTrue($tenantBFirst->contains($tenantBTransaction->transaction_id));
            $this->assertFalse($tenantBFirst->contains($tenantATransaction->transaction_id));

            $this->actingAs($userA);
            $tenantASecond = $service->getUpdatesAfter(0)->pluck('transaction_id');
            $this->assertTrue($tenantASecond->contains($tenantATransaction->transaction_id));
            $this->assertFalse($tenantASecond->contains($tenantBTransaction->transaction_id));
        });
    }

    public function test_transaction_log_updates_fail_closed_for_null_or_tenantless_users(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
        ]);

        Cache::flush();
        $service = app(TransactionLogService::class);

        $this->assertNull(auth()->user());
        $this->assertCount(0, $service->getUpdatesAfter(0));
        $this->assertFalse(Cache::has('updates.after.0.guest'));

        $tenantlessUser = User::factory()->create(['tenant_id' => null]);
        $tenantlessUser->assignRole('finance');

        $this->actingAs($tenantlessUser);
        $this->assertCount(0, $service->getUpdatesAfter(0));
        $this->assertFalse(Cache::has('updates.after.0.deny'));
    }

    private function withTenantScopeEnabledForTest(callable $callback): void
    {
        $app = app();
        $property = new \ReflectionProperty($app, 'isRunningInConsole');
        $property->setAccessible(true);
        $original = $property->getValue($app);
        $property->setValue($app, false);

        try {
            $callback();
        } finally {
            $property->setValue($app, $original);
        }
    }

    /** @test */
    public function test_transaction_detail_payload_is_completed_with_tenant_and_terminal_ids()
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-DETAIL-PAYLOAD',
            'receipt_no' => 'REC-DETAIL-PAYLOAD',
            'gross_sales' => 240.00,
            'net_sales' => 214.29,
            'payload_checksum' => str_repeat('a', 64),
            'original_payload' => json_encode([
                'transaction_id' => 'TXN-DETAIL-PAYLOAD',
                'receipt_no' => 'REC-DETAIL-PAYLOAD',
                'gross_sales' => '240.00',
                'net_sales' => '214.29',
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => '25.71'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '214.25'],
                ],
                'adjustments' => [],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson(route('transactions.logs.show', $transaction->id));

        $response->assertOk();
        $this->assertSame($this->tenant->id, $response->json('payload.tenant_id'));
        $this->assertSame($this->terminal->id, $response->json('payload.terminal_id'));
        $this->assertSame(1, $response->json('payload.transaction_count'));
        $this->assertSame('TXN-DETAIL-PAYLOAD', $response->json('payload.transaction.transaction_id'));
        $this->assertSame($this->tenant->id, $response->json('payload.transaction.tenant_id'));
        $this->assertSame($this->terminal->id, $response->json('payload.transaction.terminal_id'));
        $this->assertSame('REC-DETAIL-PAYLOAD', $response->json('payload.transaction.receipt_no'));
    }

    /** @test */
    public function test_detailed_endpoint_without_filters_uses_lightweight_pagination()
    {
        $olderTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-DETAIL-OLDER',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
        ]);

        $newerTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-DETAIL-NEWER',
            'transaction_timestamp' => '2026-06-15 10:01:00',
            'gross_sales' => 45.00,
            'net_sales' => 40.18,
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs?date_basis=transaction');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'transaction_id',
                        'receipt_no',
                    ],
                ],
                'total',
            ]);

        $this->assertSame(-1, $response->json('total'));
        $this->assertSame($newerTransaction->id, $response->json('data.0.id'));
    }

    /** @test */
    public function test_detailed_endpoint_with_tenant_only_uses_lightweight_pagination()
    {
        $matchingTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-DETAIL-TENANT',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
        ]);

        Transaction::factory()->create([
            'receipt_no' => 'REC-DETAIL-OTHER-TENANT',
            'transaction_timestamp' => '2026-06-15 10:01:00',
            'gross_sales' => 45.00,
            'net_sales' => 40.18,
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs?date_basis=transaction&tenant_id=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame(-1, $response->json('total'));
        $this->assertSame($matchingTransaction->id, $response->json('data.0.id'));
    }

    /** @test */
    public function test_detailed_endpoint_with_date_range_uses_lightweight_pagination()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-DETAIL-DATE',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs?date_basis=transaction&date_from=2026-06-15&date_to=2026-06-15');

        $response->assertOk();
        $this->assertSame(-1, $response->json('total'));
    }

    /** @test */
    public function test_transaction_date_filter_uses_manila_business_day_for_utc_timestamps()
    {
        $matchingTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-MANILA-JUNE-18',
            'transaction_timestamp' => '2026-06-17 22:03:54',
            'gross_sales' => 120.00,
            'net_sales' => 107.14,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-MANILA-JUNE-17',
            'transaction_timestamp' => '2026-06-17 15:59:59',
            'gross_sales' => 99.00,
            'net_sales' => 88.39,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs?date_basis=transaction&date_from=2026-06-18&date_to=2026-06-18&tenant_id=' . $this->tenant->id);

        $response->assertOk();
        $this->assertContains($matchingTransaction->id, collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['REC-MANILA-JUNE-18'], collect($response->json('data'))->pluck('receipt_no')->all());
    }

    /** @test */
    public function test_export_transaction_date_filter_uses_manila_business_day_for_utc_timestamps()
    {
        $matchingTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-EXPORT-MANILA-JUNE-22',
            'transaction_timestamp' => '2026-06-21 22:03:54',
            'gross_sales' => 120.00,
            'net_sales' => 107.14,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-EXPORT-MANILA-JUNE-21',
            'transaction_timestamp' => '2026-06-21 15:59:59',
            'gross_sales' => 99.00,
            'net_sales' => 88.39,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-EXPORT-MANILA-JUNE-23',
            'transaction_timestamp' => '2026-06-22 16:00:00',
            'gross_sales' => 101.00,
            'net_sales' => 90.18,
            'validation_status' => 'VALID',
        ]);

        $export = new TransactionLogsExport([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'date_from' => '2026-06-22',
            'date_to' => '2026-06-22',
            'date_basis' => 'transaction',
        ]);

        $exportedTransactions = $export->query()->get();

        $this->assertSame([$matchingTransaction->id], $exportedTransactions->pluck('id')->all());
    }

    /** @test */
    public function test_summary_endpoint_returns_react_summary_contract()
    {
        $secondTerminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-SUMMARY-1',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $secondTerminal->id,
            'receipt_no' => 'REC-SUMMARY-2',
            'transaction_timestamp' => '2026-06-15 10:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_from=2026-06-15&date_to=2026-06-15&date_basis=transaction&tenant_id=' . $this->tenant->id);

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => [
                    'data' => [
                        '*' => [
                            'date',
                            'tenant_id',
                            'terminal_id',
                            'trade_name',
                            'serial_number',
                            'tx_count',
                            'unique_receipts',
                            'gross',
                            'vat',
                            'vatable_sales',
                            'sc_vat_exempt_sales',
                            'tax_exempt',
                            'net',
                            'refund',
                            'service_charge_distributed',
                            'service_charge_retained',
                        ],
                    ],
                    'total',
                ],
                'grandTotal' => [
                    'tx_count',
                    'unique_receipts',
                    'gross',
                    'vat',
                    'net',
                    'refund',
                ],
                'dateBasisDiscrepancy',
            ]);

        $this->assertSame(-1, $response->json('summary.total'));
        $this->assertEquals(70.00, (float) $response->json('grandTotal.gross'));
        $this->assertEquals(62.50, (float) $response->json('grandTotal.net'));
        $this->assertSame(2, $response->json('grandTotal.tx_count'));
        $this->assertSame(2, $response->json('grandTotal.unique_receipts'));
        $this->assertNull($response->json('dateBasisDiscrepancy'));
    }

    /** @test */
    public function test_summary_endpoint_groups_utc_timestamps_by_manila_business_day()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-SUMMARY-MANILA-JUNE-18',
            'transaction_timestamp' => '2026-06-17 22:03:54',
            'gross_sales' => 120.00,
            'net_sales' => 107.14,
            'vat_amount' => 12.86,
            'vatable_sales' => 107.14,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_from=2026-06-18&date_to=2026-06-18&date_basis=transaction&tenant_id=' . $this->tenant->id);

        $response->assertOk();

        $this->assertSame('2026-06-18', $response->json('summary.data.0.date'));
        $this->assertSame(1, $response->json('summary.data.0.tx_count'));
        $this->assertEquals(120.00, (float) $response->json('grandTotal.gross'));
    }

    /** @test */
    public function test_summary_endpoint_recovers_discounts_from_original_payload()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-SUMMARY-PAYLOAD-DISCOUNTS',
            'transaction_timestamp' => '2026-06-18 10:01:00',
            'gross_sales' => 99.00,
            'net_sales' => 88.39,
            'vat_amount' => 10.61,
            'vatable_sales' => 88.39,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'promo_discount' => 0,
            'validation_status' => 'VALID',
            'original_payload' => json_encode([
                'adjustments' => [
                    ['adjustment_type' => 'senior_discount', 'amount' => '12.50'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '7.25'],
                    ['adjustment_type' => 'employee_discount', 'amount' => '5.00'],
                ],
            ]),
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_from=2026-06-18&date_to=2026-06-18&date_basis=transaction&tenant_id=' . $this->tenant->id);

        $response->assertOk();
        $this->assertEquals(12.50, (float) $response->json('summary.data.0.senior_discount'));
        $this->assertEquals(7.25, (float) $response->json('summary.data.0.pwd_discount'));
        $this->assertEquals(5.00, (float) $response->json('summary.data.0.employee_discount'));
        $this->assertEquals(99.00, (float) $response->json('summary.data.0.gross'));
        $this->assertEquals(123.75, (float) $response->json('summary.data.0.computed_gross_sales'));
        $this->assertEquals(24.75, (float) $response->json('summary.data.0.gross_sales_variance'));
        $this->assertEquals(12.50, (float) $response->json('grandTotal.senior_discount'));
        $this->assertEquals(7.25, (float) $response->json('grandTotal.pwd_discount'));
        $this->assertEquals(5.00, (float) $response->json('grandTotal.employee_discount'));
        $this->assertEquals(99.00, (float) $response->json('grandTotal.gross'));
        $this->assertEquals(123.75, (float) $response->json('grandTotal.computed_gross_sales'));
        $this->assertEquals(24.75, (float) $response->json('grandTotal.gross_sales_variance'));
    }

    /** @test */
    public function test_summary_endpoint_without_dates_is_rejected()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-SUMMARY-UNFILTERED',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_basis=transaction');

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Summary view requires a bounded date range.',
            ])
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    /** @test */
    public function test_summary_endpoint_rejects_ranges_beyond_configured_limit()
    {
        config(['tsms.transaction_logs.max_date_range_days' => 31]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_basis=transaction&date_from=2026-06-01&date_to=2026-07-31');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    /** @test */
    public function test_summary_endpoint_with_tenant_and_dates_returns_bounded_results()
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'receipt_no' => 'REC-SUMMARY-TENANT',
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'receipt_no' => 'REC-SUMMARY-OTHER-TENANT',
            'transaction_timestamp' => '2026-06-15 10:01:00',
            'gross_sales' => 45.00,
            'net_sales' => 40.18,
            'vat_amount' => 4.82,
            'vatable_sales' => 40.18,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/transactions/logs/summary?date_basis=transaction&date_from=2026-06-15&date_to=2026-06-15&tenant_id=' . $this->tenant->id);

        $response->assertOk();
        $this->assertNotEmpty($response->json('summary.data'));
        $this->assertSame(-1, $response->json('summary.total'));
        $this->assertEquals(35.00, (float) $response->json('grandTotal.gross'));
    }

    /** @test */
    public function test_finance_users_can_export_transaction_logs_with_table_filters()
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $financeUser = User::factory()->create([
            'name' => 'Finance User',
        ]);
        $financeUser->assignRole('finance');

        $matchingTransaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-EXPORT-MATCH',
            'transaction_timestamp' => '2026-06-10 12:30:00',
            'gross_sales' => 1250.00,
            'net_sales' => 1120.00,
            'vat_amount' => 130.00,
            'vatable_sales' => 1083.33,
            'sc_vat_exempt_sales' => 0.00,
            'tax_exempt' => false,
            'service_charge' => 15.00,
            'management_service_charge' => 25.00,
            'validation_status' => 'VALID',
        ]);

        DB::table('transaction_adjustments')->insert([
            [
                'transaction_pk' => $matchingTransaction->id,
                'adjustment_type' => 'promo_discount',
                'amount' => 11.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_pk' => $matchingTransaction->id,
                'adjustment_type' => 'senior_discount',
                'amount' => 22.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_pk' => $matchingTransaction->id,
                'adjustment_type' => 'pwd_discount',
                'amount' => 33.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_pk' => $matchingTransaction->id,
                'adjustment_type' => 'VIP',
                'amount' => 44.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_pk' => $matchingTransaction->id,
                'adjustment_type' => 'EMPLOYEE',
                'amount' => 55.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('transaction_taxes')->insert([
            [
                'transaction_pk' => $matchingTransaction->id,
                'tax_type' => 'SC_VAT_EXEMPT_SALES',
                'amount' => 66.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'transaction_pk' => $matchingTransaction->id,
                'tax_type' => 'LOCAL_TAX',
                'amount' => 77.00,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Transaction::factory()->create([
            'transaction_id' => 'TXN-EXPORT-OTHER-TENANT',
            'transaction_timestamp' => '2026-06-10 12:30:00',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-EXPORT-OUTSIDE-DATE',
            'transaction_timestamp' => '2026-05-31 15:59:59',
        ]);

        Sanctum::actingAs($financeUser);

        $response = $this->getJson('/api/transactions/logs/export?tenant_id=' . $this->tenant->id . '&date_from=2026-06-01&date_to=2026-06-14&date_basis=transaction');

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringStartsWith('PK', $response->streamedContent());
        $this->assertTrue(Gate::forUser($financeUser)->allows('export-transaction-logs'));

        $export = new TransactionLogsExport([
            'tenant_id' => $this->tenant->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-14',
            'date_basis' => 'transaction',
        ]);
        $exportedTransactions = $export->query()->get();

        $this->assertSame([$matchingTransaction->id], $exportedTransactions->pluck('id')->all());
        $matchingTransaction->load(['adjustments', 'taxes']);

        $headings = $export->headings();
        $mapped = $export->map($matchingTransaction);

        $this->assertContains('Vatable Sales', $headings);
        $this->assertContains('SC VAT Exempt Sales', $headings);
        $this->assertContains('Promo Discount', $headings);
        $this->assertContains('Senior Discount', $headings);
        $this->assertContains('PWD Discount', $headings);
        $this->assertContains('VIP Card Discount', $headings);
        $this->assertContains('Employee Discount', $headings);
        $this->assertSame('Test Tenant', $mapped[1]);
        $this->assertNotSame('N/A', $mapped[2]);
        $this->assertSame('1,083.33', $mapped[7]);
        $this->assertSame('66.00', $mapped[8]);
        $this->assertSame('No', $mapped[9]);
        $this->assertSame('11.00', $mapped[10]);
        $this->assertSame('22.00', $mapped[11]);
        $this->assertSame('33.00', $mapped[12]);
        $this->assertSame('44.00', $mapped[13]);
        $this->assertSame('55.00', $mapped[14]);
        $this->assertSame('15.00', $mapped[16]);
        $this->assertSame('25.00', $mapped[17]);
        $this->assertSame('77.00', $mapped[18]);

        $matchingTransaction->setRawAttributes(array_merge($matchingTransaction->getAttributes(), [
            'transaction_timestamp' => '2026-06-10 12:30:00',
            'completed_at' => '2026-06-10 12:31:00',
            'created_at' => '2026-06-10 12:32:00',
        ]), true);

        $mapped = $export->map($matchingTransaction);

        $this->assertSame('2026-06-10 12:30:00', $mapped[22]);
        $this->assertSame('2026-06-10 12:31:00', $mapped[23]);
        $this->assertSame('2026-06-10 12:32:00', $mapped[24]);
    }

    /** @test */
    public function test_export_requires_bounded_dates()
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $financeUser = User::factory()->create([
            'name' => 'Finance User',
        ]);
        $financeUser->assignRole('finance');

        Sanctum::actingAs($financeUser);

        $response = $this->getJson('/api/transactions/logs/export?date_basis=transaction');

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Export requires a bounded date range.',
            ])
            ->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    /** @test */
    public function test_export_rejects_ranges_beyond_configured_limit()
    {
        config(['tsms.transaction_logs.max_date_range_days' => 31]);

        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $financeUser = User::factory()->create([
            'name' => 'Finance User',
        ]);
        $financeUser->assignRole('finance');

        Sanctum::actingAs($financeUser);

        $response = $this->getJson('/api/transactions/logs/export?date_basis=transaction&date_from=2026-06-01&date_to=2026-07-31');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }
}
