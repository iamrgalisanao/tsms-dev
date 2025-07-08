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
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            $tenantId = DB::table('tenants')->where('code', 'SAMPLE001')->value('id');
        }

        DB::table('circuit_breakers')->insert([
            [
                'tenant_id' => $tenantId,
                'name' => 'payment_gateway',
                'status' => 'CLOSED',
                'trip_count' => 0,
                'failure_threshold' => 5,
                'reset_timeout' => 60,
                'last_failure_at' => null,
                'cooldown_until' => null,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'name' => 'sms_service',
                'status' => 'HALF_OPEN',
                'trip_count' => 3,
                'failure_threshold' => 5,
                'reset_timeout' => 60,
                'last_failure_at' => now(),
                'cooldown_until' => now()->addMinutes(1),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'name' => 'email_service',
                'status' => 'OPEN',
                'trip_count' => 5,
                'failure_threshold' => 5,
                'reset_timeout' => 60,
                'last_failure_at' => now(),
                'cooldown_until' => now()->addMinutes(1),
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    public function down(): void
    {
        // Remove circuit breakers
        DB::table('circuit_breakers')->whereIn('name', [
            'payment_gateway',
            'sms_service',
            'email_service'
        ])->delete();


        // Remove the tenant if it has no other dependencies
        DB::table('tenants')->where('code', 'SAMPLE001')->delete();
    }
};
