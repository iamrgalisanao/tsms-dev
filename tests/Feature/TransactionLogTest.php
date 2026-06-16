<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use App\Exports\TransactionLogsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

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

        $this->assertSame(2, $response->json('summary.total'));
        $this->assertEquals(70.00, (float) $response->json('grandTotal.gross'));
        $this->assertEquals(62.50, (float) $response->json('grandTotal.net'));
        $this->assertSame(2, $response->json('grandTotal.tx_count'));
        $this->assertSame(2, $response->json('grandTotal.unique_receipts'));
        $this->assertNull($response->json('dateBasisDiscrepancy'));
    }

    /** @test */
    public function test_summary_endpoint_without_filters_uses_lightweight_pagination()
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

        $response->assertOk()
            ->assertJsonStructure([
                'summary' => [
                    'data',
                    'total',
                ],
                'grandTotal',
                'dateBasisDiscrepancy',
            ]);

        $this->assertNotEmpty($response->json('summary.data'));
        $this->assertSame(-1, $response->json('summary.total'));
        $this->assertNull($response->json('grandTotal'));
        $this->assertNull($response->json('dateBasisDiscrepancy'));
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
            'transaction_timestamp' => '2026-05-31 23:59:59',
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
}
