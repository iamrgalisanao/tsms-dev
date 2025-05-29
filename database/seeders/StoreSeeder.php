<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StoreSeeder extends Seeder
{
    public function run()
    {
        $defaultHours = [
            'monday' => ['open' => '06:00', 'close' => '22:00'],
            'tuesday' => ['open' => '06:00', 'close' => '22:00'],
            'wednesday' => ['open' => '06:00', 'close' => '22:00'],
            'thursday' => ['open' => '06:00', 'close' => '22:00'],
            'friday' => ['open' => '06:00', 'close' => '22:00'],
            'saturday' => ['open' => '06:00', 'close' => '22:00'],
            'sunday' => ['open' => '06:00', 'close' => '22:00'],
        ];

        // Create sample stores for each tenant
        $tenants = DB::table('tenants')->pluck('id');

        foreach ($tenants as $tenantId) {
            // Create main branch store
            DB::table('stores')->insert([
                'tenant_id' => $tenantId,
                'name' => 'Main Branch',
                'address' => '123 Main Street',
                'city' => 'Metro Manila',
                'state' => 'NCR',
                'postal_code' => '1001',
                'phone' => '+63 2 8123 4567',
                'email' => 'store@example.com',
                'operating_hours' => json_encode($defaultHours),
                'status' => 'active',
                'allows_service_charge' => true,
                'tax_exempt' => false,
                'max_daily_sales' => 1000000.00,
                'max_transaction_amount' => 50000.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}