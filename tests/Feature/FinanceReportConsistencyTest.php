<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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

            'year' => 2025,
            'month' => 1
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
}
