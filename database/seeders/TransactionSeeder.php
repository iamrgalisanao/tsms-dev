<?php

namespace Database\Seeders;

use App\Models\Transactions;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create([
            'code' => 'C-T1005',
            'name' => 'ABC Store #102',
            'status' => 'active'
        ]);

        // Create sample transactions
        Transactions::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'store_name' => 'ABC Store #102',
            'transaction_timestamp' => Carbon::parse('2025-03-26T13:45:00Z'),
            'vatable_sales' => 12000.00,
            'net_sales' => 18137.00,
            'vat_exempt_sales' => 6137.00,
            'promo_discount_amount' => 100.00,
            'promo_status' => 'WITH_APPROVAL',
            'discount_total' => 50.00,
            'discount_details' => json_encode([
                'Employee' => '20.00',
                'Senior' => '30.00'
            ]),
            'other_tax' => 50.00,
            'management_service_charge' => 8.50,
            'employee_service_charge' => 4.00,
            'gross_sales' => 12345.67,
            'vat_amount' => 1500.00,
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', ''),
            'validation_status' => 'VALID',
            'error_code' => null
        ]);

        // Create additional transactions with variations
        Transactions::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'store_name' => 'ABC Store #102',
            'transaction_timestamp' => Carbon::now(),
            'vatable_sales' => 8500.00,
            'net_sales' => 9500.00,
            'vat_exempt_sales' => 1000.00,
            'promo_discount_amount' => 0.00,
            'promo_status' => null,
            'discount_total' => 150.00,
            'discount_details' => json_encode([
                'PWD' => '150.00'
            ]),
            'other_tax' => 25.00,
            'management_service_charge' => 5.50,
            'employee_service_charge' => 2.00,
            'gross_sales' => 9682.50,
            'vat_amount' => 1020.00,
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', ''),
            'validation_status' => 'VALID',
            'error_code' => null
        ]);

        // Create an invalid transaction example
        Transactions::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'store_name' => 'ABC Store #102',
            'transaction_timestamp' => Carbon::now()->subHours(2),
            'vatable_sales' => -100.00, // Invalid negative amount
            'net_sales' => 0.00,
            'vat_exempt_sales' => 0.00,
            'promo_discount_amount' => 0.00,
            'promo_status' => null,
            'discount_total' => 0.00,
            'discount_details' => null,
            'other_tax' => 0.00,
            'management_service_charge' => 0.00,
            'employee_service_charge' => 0.00,
            'gross_sales' => -100.00,
            'vat_amount' => 0.00,
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', ''),
            'validation_status' => 'ERROR',
            'error_code' => 'NEGATIVE_AMOUNT'
        ]);
    }
}
