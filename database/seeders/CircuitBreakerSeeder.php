<?php


namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\CircuitBreaker;
use Illuminate\Database\Seeder;

class CircuitBreakerSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            CircuitBreaker::create([
                'tenant_id' => $tenant->id,
                'name' => 'test_service',
                'status' => 'CLOSED',
                'trip_count' => 0,
                'failure_threshold' => 5,
                'reset_timeout' => 60
            ]);
        }
    }
}