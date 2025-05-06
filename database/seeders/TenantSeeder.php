<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            [
                'name' => 'Demo Company',
                'code' => 'DEMO',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Test Company',
                'code' => 'TEST',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($tenants as $tenant) {
            Tenant::create($tenant);
        }
    }
}