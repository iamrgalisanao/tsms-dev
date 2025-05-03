<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First create a tenant if it doesn't exist
        if (!DB::table('tenants')->where('code', 'SAMPLE001')->exists()) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Sample Tenant',
                'code' => 'SAMPLE001',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            $tenantId = DB::table('tenants')->where('code', 'SAMPLE001')->value('id');
        }

        // Then add circuit breaker data
        DB::table('circuit_breakers')->insert([
            [
                'tenant_id' => $tenantId,
                'service_name' => 'Payment Gateway',
                'status' => 'CLOSED',
                'trip_count' => 0,
                'failure_threshold' => 5,
                'reset_timeout' => 300,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'service_name' => 'SMS Service',
                'status' => 'HALF_OPEN',
                'trip_count' => 3,
                'failure_threshold' => 5,
                'reset_timeout' => 300,
                'last_failure_at' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'service_name' => 'Email Service',
                'status' => 'OPEN',
                'trip_count' => 5,
                'failure_threshold' => 5,
                'reset_timeout' => 300,
                'last_failure_at' => now(),
                'cooldown_until' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    public function down(): void
    {
        // Remove circuit breakers
        DB::table('circuit_breakers')->whereIn('service_name', [
            'Payment Gateway',
            'SMS Service',
            'Email Service'
        ])->delete();

        // Remove the tenant if it has no other dependencies
        DB::table('tenants')->where('code', 'SAMPLE001')->delete();
    }
};
