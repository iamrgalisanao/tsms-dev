<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

class FinanceReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ui_and_export_produce_consistent_totals()
    {
        // 1. Setup Data
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $tenant = Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id
        ]);
        $user = User::factory()->create();
        $user->assignRole('finance');

        // Create some transactions for a specific month
        $date = '2025-01-15 12:00:00';

        // Transaction with multiple components
        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => $date,
            'completed_at' => $date,
            'vatable_sales' => 1000.00,
            'sc_vat_exempt_sales' => 500.00,
            'vat_amount' => 120.00,
            'gross_sales' => 2000.00,
            'promo_discount' => 150.00,
            'promo_status' => 'WITH_APPROVAL',
            'senior_discount' => 30.00,
            'pwd_discount' => 10.00,
            'service_charge' => 40.00,
            'management_service_charge' => 10.00,
            'payload_checksum' => 'test-checksum',
            'customer_code' => 'TEST',
            'validation_status' => 'VALID'
        ]);

        // Add a tax record for "Other Tax"
        \App\Models\TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'LOCAL_TAX',
            'amount' => 5.00
        ]);

        // 2. Fetch UI via Controller logic (simulating AJAX call)
        $this->actingAs($user);
        $response = $this->getJson(route('finance.reports', [
            'trade' => $tenant->id,
            'month' => '2025-01'
        ]));

        $response->assertStatus(200);
        $uiTotals = $response->json('totals');

        // 3. Directly test the shared service logic (which Export also uses)
        $service = new FinanceCalculationService();
        $transactions = Transaction::where('tenant_id', $tenant->id)->get();
        $components = $service->aggregateComponents($transactions);
        $exportTotals = $service->deriveMetrics($components);

        // 4. Assert Consistency
        $this->assertEquals($uiTotals['net_sales'], $exportTotals['net_sales'], 'UI and Export Net Sales must match');
        $this->assertEquals($uiTotals['vat_amount'], $exportTotals['vat_amount'], 'UI and Export VAT must match');
        $this->assertEquals($uiTotals['gross_sales'], $exportTotals['gross_sales'], 'UI and Export Gross Sales must match');
        $this->assertEquals($uiTotals['net_subject_to_rent'], $exportTotals['net_subject_to_rent'], 'UI and Export Net Subject to Rent must match');

        // Verify key formulas
        $this->assertEquals(150.00, $uiTotals['total_promotions']);
        $this->assertEquals(50.00, $uiTotals['total_service_charge']);
    }

    public function test_csmr_report_uses_completed_date_for_reporting_month()
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $tenant = Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id
        ]);
        $user = User::factory()->create();
        $user->assignRole('finance');

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => '2026-05-31 23:59:00',
            'completed_at' => '2026-06-01 00:01:00',
            'gross_sales' => 100.00,
            'net_sales' => 90.00,
            'vatable_sales' => 80.00,
            'vat_amount' => 9.60,
            'payload_checksum' => 'completed-june',
            'customer_code' => 'TEST',
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => '2026-04-30 23:59:00',
            'completed_at' => '2026-05-31 00:01:00',
            'gross_sales' => 200.00,
            'net_sales' => 180.00,
            'vatable_sales' => 160.00,
            'vat_amount' => 19.20,
            'payload_checksum' => 'completed-may',
            'customer_code' => 'TEST',
            'validation_status' => 'VALID',
        ]);

        $this->actingAs($user);
        $response = $this->getJson(route('finance.reports', [
            'trade' => $tenant->id,
            'month' => '2026-05',
        ]));

        $response->assertStatus(200);
        $service = new FinanceCalculationService();
        $expectedTotals = $service->deriveMetrics([
            'vatable_sales' => 160.00,
            'sc_vat_exempt_sales' => 0.00,
            'vat_amount' => 19.20,
            'promo_with_approval' => 0.00,
            'promo_without_approval' => 0.00,
            'employee_discount' => 0.00,
            'senior_discount' => 0.00,
            'pwd_discount' => 0.00,
            'vip_discount' => 0.00,
            'other_tax' => 0.00,
            'service_charge_distributed' => 0.00,
            'service_charge_retained' => 0.00,
            'regular_discount' => 0.00,
            'gross_sales' => 200.00,
            'net_sales' => 180.00,
        ]);

        $this->assertEquals($expectedTotals['gross_sales'], $response->json('totals.gross_sales'));
        $this->assertArrayHasKey('2026-05-31', $response->json('daily_totals'));
        $this->assertArrayNotHasKey('2026-06-01', $response->json('daily_totals'));
    }

    public function test_canonical_employee_discount_adjustments_are_reported_in_csmr_and_transaction_logs()
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $tenant = Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->assignRole('finance');

        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => '2026-05-31 14:11:31',
            'completed_at' => '2026-05-31 14:12:00',
            'gross_sales' => 255.00,
            'net_sales' => 0.00,
            'vatable_sales' => 0.00,
            'vat_amount' => 0.00,
            'sc_vat_exempt_sales' => 0.00,
            'payload_checksum' => 'employee-discount-subway',
            'customer_code' => 'TEST',
            'validation_status' => 'VALID',
        ]);

        TransactionAdjustment::create([
            'transaction_pk' => $tx->id,
            'adjustment_type' => 'employee_discount',
            'amount' => 255.00,
        ]);

        $this->actingAs($user);
        $financeResponse = $this->getJson(route('finance.reports', [
            'trade' => $tenant->id,
            'month' => '2026-05',
        ]));

        $financeResponse->assertStatus(200);
        $this->assertSame(255.0, (float) $financeResponse->json('totals.employee_discount'));
        $this->assertSame(255.0, (float) $financeResponse->json('daily_totals.2026-05-31.employee_discount'));

        $summaryResponse = $this->getJson(route('transactions.logs.summary', [
            'tenant_id' => $tenant->id,
            'date_from' => '2026-05-31',
            'date_to' => '2026-05-31',
            'date_basis' => 'completed',
        ]));

        $summaryResponse->assertStatus(200);
        $this->assertSame(255.0, (float) $summaryResponse->json('grandTotal.employee_discount'));
        $this->assertSame(255.0, (float) $summaryResponse->json('summary.data.0.employee_discount'));

        Sanctum::actingAs($user, ['*']);
        $logsResponse = $this->getJson('/api/transactions/logs?' . http_build_query([
            'tenant_id' => $tenant->id,
            'date_from' => '2026-05-31',
            'date_to' => '2026-05-31',
            'date_basis' => 'completed',
        ]));

        $logsResponse->assertStatus(200);
        $this->assertSame(255.0, (float) $logsResponse->json('data.0.employee_discount'));
    }
}
