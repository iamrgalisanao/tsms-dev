<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;
use App\Models\Tenant;
use App\Models\PosTerminal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RetryTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Direct DB approach for more reliable seeding
        try {
            // Find or create tenant
            $tenant = DB::table('tenants')->first();
            
            if (!$tenant) {
                $tenantId = 'tenant-' . Str::random(8);
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => 'Test Tenant',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $tenantId = $tenant->id;
            }
            
            // Create terminal if none exists
            $terminal = DB::table('pos_terminals')->first();
            
            if (!$terminal) {
                $terminalId = 'term-' . Str::random(8);
                DB::table('pos_terminals')->insert([
                    'id' => $terminalId,
                    'tenant_id' => $tenantId,
                    'terminal_uid' => 'TERM-TEST',
                    'serial_number' => 'SN12345',
                    'model' => 'TEST-MODEL',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $terminalId = $terminal->id;
            }
            
            // Create test transactions with retry attempts
            $statuses = ['COMPLETED', 'FAILED', 'QUEUED', 'PROCESSING'];
            $successCount = 0;
            
            // Delete existing test transactions to avoid conflicts
            DB::table('transactions')
                ->where('transaction_id', 'like', 'TEST-RETRY-%')
                ->delete();
                
            // Create 5 test transactions
            for ($i = 1; $i <= 5; $i++) {
                $status = $statuses[array_rand($statuses)];
                $now = now();
                
                try {
                    $id = DB::table('transactions')->insertGetId([
                        'tenant_id' => $tenantId,
                        'transaction_id' => 'TEST-RETRY-' . $i,
                        'terminal_id' => $terminalId,
                        'transaction_timestamp' => $now->subHours(rand(1, 10)),
                        'job_attempts' => rand(1, 5),
                        'job_status' => $status,
                        'validation_status' => $status == 'COMPLETED' ? 'VALID' : 'INVALID',
                        'last_error' => $status == 'FAILED' ? 'Error: Test validation failed' : null,
                        'gross_sales' => $amount = rand(100, 1000),
                        'net_sales' => $net = round($amount / 1.12, 2),
                        'vatable_sales' => $net,
                        'vat_amount' => round($amount - $net, 2),
                        'transaction_count' => 1,
                        'created_at' => $now->subHours(rand(1, 10)),
                        'updated_at' => now()->subMinutes(rand(5, 30))
                    ]);
                    
                    $successCount++;
                    echo "Created transaction {$i} with ID {$id}\n";
                } catch (\Exception $e) {
                    echo "Error creating transaction {$i}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "\nSuccessfully created {$successCount} test retry transactions\n";
            echo "Total retry transactions in database: " . 
                DB::table('transactions')->where('job_attempts', '>', 0)->count() . "\n";
                
        } catch (\Exception $e) {
            echo "Critical error in seeding: " . $e->getMessage() . "\n";
        }
    }
}