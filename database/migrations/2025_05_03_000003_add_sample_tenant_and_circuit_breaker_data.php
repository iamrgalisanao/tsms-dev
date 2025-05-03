<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get existing tenant or create a new one
        $tenant = DB::table('tenants')->where('code', 'SAMPLE001')->first();
        
        if (!$tenant) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Sample Tenant',
                'code' => 'SAMPLE001',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            $tenantId = $tenant->id;
        }

        // Then add circuit breaker data
        DB::table('circuit_breakers')->insert([
            [
                'tenant_id' => $tenantId,
                'name' => 'Transaction Processing Service',
                'status' => 'CLOSED',
                'last_failure_at' => null,
                'cooldown_until' => null,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'name' => 'Terminal Authentication Service',
                'status' => 'HALF_OPEN',
                'last_failure_at' => now(),
                'cooldown_until' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tenant_id' => $tenantId,
                'name' => 'Webhook Delivery Service',
                'status' => 'OPEN',
                'last_failure_at' => now(),
                'cooldown_until' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    public function down(): void
    {
        // First remove circuit breakers by names
        DB::table('circuit_breakers')->whereIn('name', [
            'Terminal Authentication Service',
            'SMS Service',
            'Email Service'
        ])->where('tenant_id', function($query) {
            $query->select('id')
                ->from('tenants')
                ->where('code', 'SAMPLE001')
                ->limit(1);
        })->delete();

        // Then remove the tenant
        DB::table('tenants')->where('code', 'SAMPLE001')->delete();
    }
};