<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Store;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\Tenant;
use Carbon\Carbon;

class TestTransactionPipelineSeeder extends Seeder
{
    /**
     * Run the database seeder.
     *
     * @return void
     */
    public function run()
    {
        // Create a test tenant if one doesn't exist
        $tenant = Tenant::first() ?? Tenant::factory()->create([
            'name' => 'Test Tenant',
            'status' => 'active'
        ]);
        
        // Create test stores with different configurations
        $regularStore = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Regular Store',
            'allows_service_charge' => true,
            'tax_exempt' => false
        ]);
        
        $taxExemptStore = Store::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Tax Exempt Store',
            'allows_service_charge' => false,
            'tax_exempt' => true
        ]);
        
        // Create terminals for each store
        $regularTerminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'store_id' => $regularStore->id,
            'terminal_uid' => 'REG-TERM-001',
            'status' => 'active'
        ]);
        
        $taxExemptTerminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id, 
            'store_id' => $taxExemptStore->id,
            'terminal_uid' => 'TAX-TERM-001',
            'status' => 'active'
        ]);
        
        // Create test transactions to test various scenarios
        
        // 1. Valid transaction with correct VAT
        Transaction::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => 'TEST-VALID-001',
            'terminal_id' => $regularTerminal->id,
            'transaction_timestamp' => Carbon::now(),
            'gross_sales' => 1120.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00, // 12% of 1000
            'service_charge' => 100.00, // 10% of net sales
            'job_status' => 'QUEUED',
            'validation_status' => 'PENDING',
            'transaction_count' => 1,
        ]);
        
        // 2. Transaction with incorrect VAT calculation
        Transaction::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => 'TEST-INVALID-VAT-001',
            'terminal_id' => $regularTerminal->id,
            'transaction_timestamp' => Carbon::now(),
            'gross_sales' => 1150.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00, 
            'vat_amount' => 150.00, // Should be 120.00 (12% of 1000)
            'job_status' => 'QUEUED',
            'validation_status' => 'PENDING',
            'transaction_count' => 1,
        ]);
        
        // 3. Transaction with excessive discount
        Transaction::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => 'TEST-INVALID-DISCOUNT-001',
            'terminal_id' => $regularTerminal->id,
            'transaction_timestamp' => Carbon::now(),
            'gross_sales' => 1120.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'discount_amount' => 700.00, // Over 50% of gross sales
            'job_status' => 'QUEUED',
            'validation_status' => 'PENDING',
            'transaction_count' => 1,
        ]);
        
        // 4. Tax exempt transaction with VAT (should fail)
        Transaction::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => 'TEST-INVALID-TAXEXEMPT-001',
            'terminal_id' => $taxExemptTerminal->id,
            'transaction_timestamp' => Carbon::now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 0.00,
            'vat_amount' => 120.00, // Should be 0.00 for tax exempt
            'tax_exempt' => true,
            'job_status' => 'QUEUED',
            'validation_status' => 'PENDING',
            'transaction_count' => 1,
        ]);
        
        // 5. Transaction outside store hours (use Sunday midnight)
        Transaction::create([
            'tenant_id' => $tenant->id,
            'transaction_id' => 'TEST-INVALID-HOURS-001',
            'terminal_id' => $regularTerminal->id,
            'transaction_timestamp' => Carbon::now()->next('Sunday')->setTime(3, 0), // 3:00 AM Sunday
            'gross_sales' => 1120.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'job_status' => 'QUEUED',
            'validation_status' => 'PENDING',
            'transaction_count' => 1,
        ]);

        echo "Created test transactions for Pipeline testing.\n";
    }
}