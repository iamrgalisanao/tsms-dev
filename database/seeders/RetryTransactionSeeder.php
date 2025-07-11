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
            // Find or create company first (required for tenants)
            $company = DB::table('companies')->first();
            
            if (!$company) {
                $companyId = DB::table('companies')->insertGetId([
                    'customer_code' => 'TEST-COMP-001',
                    'company_name' => 'Test Company',
                    'tin' => '123456789000',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $companyId = $company->id;
            }
            
            // Find or create tenant with correct schema
            $tenant = DB::table('tenants')->first();
            
            if (!$tenant) {
                $tenantId = DB::table('tenants')->insertGetId([
                    'company_id' => $companyId,
                    'trade_name' => 'Test Tenant',
                    'location_type' => 'Kiosk',
                    'location' => 'Test Mall',
                    'unit_no' => 'K-001',
                    'floor_area' => 25.00,
                    'status' => 'Operational',
                    'category' => 'F&B',
                    'zone' => 'Food Court',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $tenantId = $tenant->id;
            }
            
            // Create terminal if none exists (using correct schema)
            $terminal = DB::table('pos_terminals')->first();
            
            if (!$terminal) {
                // Get the 'active' status ID
                $activeStatusId = DB::table('terminal_statuses')->where('name', 'active')->value('id');
                
                if (!$activeStatusId) {
                    throw new \Exception('Active terminal status not found. Make sure terminal_statuses are seeded.');
                }
                
                $terminalId = DB::table('pos_terminals')->insertGetId([
                    'tenant_id' => $tenantId,
                    'serial_number' => 'TEST-SN-' . Str::random(6),
                    'machine_number' => 'MACH-001',
                    'supports_guest_count' => false,
                    'pos_type_id' => null, // Optional
                    'integration_type_id' => null, // Optional
                    'auth_type_id' => null, // Optional
                    'status_id' => $activeStatusId,
                    'expires_at' => null,
                    'registered_at' => now(),
                    'last_seen_at' => now(),
                    'heartbeat_threshold' => 300,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } else {
                $terminalId = $terminal->id;
            }
            
            // Ensure job statuses exist first
            $requiredJobStatuses = [
                ['code' => 'QUEUED', 'description' => 'Job has been created and is awaiting execution'],
                ['code' => 'RUNNING', 'description' => 'Job is currently in progress'],
                ['code' => 'RETRYING', 'description' => 'Job failed but is scheduled for another attempt'],
                ['code' => 'COMPLETED', 'description' => 'Job finished successfully'],
                ['code' => 'PERMANENTLY_FAILED', 'description' => 'Job has failed after maximum retries and will not be retried'],
            ];
            
            foreach ($requiredJobStatuses as $status) {
                DB::table('job_statuses')->updateOrInsert(
                    ['code' => $status['code']],
                    $status
                );
            }
            
            // Create test transactions with retry attempts using correct schema
            $jobStatuses = ['QUEUED', 'RUNNING', 'RETRYING', 'COMPLETED', 'PERMANENTLY_FAILED'];
            $validationStatuses = ['PENDING', 'VALID', 'INVALID'];
            $successCount = 0;
            
            // Delete existing test transactions to avoid conflicts
            DB::table('transactions')
                ->where('transaction_id', 'like', 'TEST-RETRY-%')
                ->delete();
                
            // Create 5 test transactions with proper schema
            for ($i = 1; $i <= 5; $i++) {
                $now = now();
                $transactionId = 'TEST-RETRY-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                
                try {
                    // Create transaction with correct schema
                    DB::table('transactions')->insert([
                        'tenant_id' => $tenantId,
                        'terminal_id' => $terminalId,
                        'transaction_id' => $transactionId,
                        'hardware_id' => 'HW-TEST-' . $i,
                        'transaction_timestamp' => $now->subHours(rand(1, 10)),
                        'base_amount' => $amount = rand(100, 1000),
                        'customer_code' => 'TEST-CUST-' . $i,
                        'payload_checksum' => md5('test-payload-' . $i),
                        'validation_status' => $validationStatuses[array_rand($validationStatuses)],
                        'created_at' => $now->subHours(rand(1, 10)),
                        'updated_at' => now()->subMinutes(rand(5, 30))
                    ]);
                    
                    // Create corresponding transaction job with retry data
                    $jobStatus = $jobStatuses[array_rand($jobStatuses)];
                    $attempts = rand(1, 5);
                    
                    // Ensure some transactions have retry counts for testing
                    $retryCount = 0;
                    if ($jobStatus === 'RETRYING') {
                        $retryCount = rand(1, 3);
                    } elseif ($jobStatus === 'PERMANENTLY_FAILED') {
                        $retryCount = rand(3, 5); // Failed after multiple retries
                    } elseif ($jobStatus === 'COMPLETED' && rand(1, 3) === 1) {
                        $retryCount = rand(1, 2); // Some completed after retries
                    }
                    
                    DB::table('transaction_jobs')->insert([
                        'transaction_id' => $transactionId,
                        'job_status' => $jobStatus,
                        'last_error' => $jobStatus === 'PERMANENTLY_FAILED' ? 'Test validation failed after max retries' : 
                                      ($jobStatus === 'RETRYING' ? 'Connection timeout, retrying...' : null),
                        'attempts' => $attempts,
                        'retry_count' => $retryCount,
                        'completed_at' => in_array($jobStatus, ['COMPLETED', 'PERMANENTLY_FAILED']) ? $now->subMinutes(rand(5, 60)) : null,
                        'created_at' => $now->subHours(rand(1, 10)),
                        'updated_at' => now()->subMinutes(rand(5, 30))
                    ]);
                    
                    $successCount++;
                    echo "Created transaction {$transactionId} with job status {$jobStatus}\n";
                } catch (\Exception $e) {
                    echo "Error creating transaction {$i}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "\nSuccessfully created {$successCount} test retry transactions\n";
            echo "Total transactions with retry data: " . 
                DB::table('transaction_jobs')->where('retry_count', '>', 0)->count() . "\n";
                
        } catch (\Exception $e) {
            echo "Critical error in seeding: " . $e->getMessage() . "\n";
        }
    }
}