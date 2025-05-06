<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\CircuitBreaker;
use Illuminate\Database\Seeder;

class AddSampleCircuitBreakerData extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first();

        if (!$tenant) {
            $tenant = Tenant::factory()->create();
        }

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
