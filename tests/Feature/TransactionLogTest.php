<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use App\Exports\TransactionLogsExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'validation_status' => 'VALID',
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
        $this->assertSame('Test Tenant', $export->map($matchingTransaction)[1]);
        $this->assertNotSame('N/A', $export->map($matchingTransaction)[2]);
    }
}
