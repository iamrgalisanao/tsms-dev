<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TestLogSeeder extends Seeder
{
    public function run()
    {
        // Check if migration has been run - do not proceed if columns don't exist
        if (!$this->requiredColumnsExist()) {
            $this->command->error('Required columns are missing from integration_logs table. Please run migrations first.');
            return;
        }
    
        // Create test terminals if needed
        if (PosTerminal::count() < 5) {
            PosTerminal::factory()->count(5)->create();
            $this->command->info('Created POS terminals');
        }
        
        $terminals = PosTerminal::take(5)->get();
        
        // Check if we have users and tenants
        if (User::count() == 0) {
            User::factory()->count(3)->create();
            $this->command->info('Created users');
        }
        
        if (Tenant::count() == 0) {
            Tenant::factory()->count(2)->create();
            $this->command->info('Created tenants');
        }
        
        $users = User::take(3)->get();
        $tenants = Tenant::take(2)->get();
        
        // Create transaction logs
        foreach ($terminals as $index => $terminal) {
            // Create logs only for the basic fields that exist in all database states
            $this->createBasicLogs($terminal, $tenants->random(), 10);
        }
        
        $this->command->info('Created ' . IntegrationLog::count() . ' test log entries');
    }
    
    /**
     * Check if the required columns exist in the integration_logs table
     */
    protected function requiredColumnsExist()
    {
        // Check for basic required columns - these should be in the original schema
        return \Schema::hasColumns('integration_logs', [
            'tenant_id', 'terminal_id', 'transaction_id', 'status'
        ]);
    }
    
    /**
     * Create basic logs that work with any database schema
     */
    protected function createBasicLogs($terminal, $tenant, $count)
    {
        // First check the status column constraints
        $statusColumn = $this->getStatusColumnInfo();
        $validStatuses = $statusColumn['valid_values'] ?? ['SUCCESS', 'FAILED'];
        
        // Add 'PENDING' only if it's allowed by the column type
        if ($statusColumn['type'] === 'varchar' || $statusColumn['type'] === 'string' || in_array('PENDING', $validStatuses)) {
            $validStatuses = array_merge($validStatuses, ['PENDING']);
        }
        
        for ($i = 0; $i < $count; $i++) {
            $status = fake()->randomElement($validStatuses);
            $retryCount = $status === 'SUCCESS' ? fake()->numberBetween(0, 2) : fake()->numberBetween(1, 5);
            
            // Build data array with only the original fields
            $data = [
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'transaction_id' => (string) \Illuminate\Support\Str::uuid(),
                'request_payload' => json_encode(['amount' => fake()->randomFloat(2, 10, 1000)]),
                'response_payload' => json_encode(['status' => $status]),
                'status' => $status,
                'error_message' => $status === 'FAILED' ? fake()->sentence() : null,
                'http_status_code' => $status === 'SUCCESS' ? 200 : fake()->randomElement([400, 500]),
                'source_ip' => fake()->ipv4(),
                'retry_count' => $retryCount,
                'retry_reason' => $retryCount > 0 ? fake()->sentence() : null,
                'validation_status' => $status === 'SUCCESS' ? 'PASSED' : 'FAILED',
                'response_time' => fake()->numberBetween(100, 5000),
                'retry_attempts' => $retryCount > 0 ? $retryCount - 1 : 0,
            ];
            
            // Conditionally add fields if they exist in the schema
            if (\Schema::hasColumn('integration_logs', 'log_type')) {
                $data['log_type'] = fake()->randomElement(['transaction', 'auth', 'error', 'security']);
            }
            
            if (\Schema::hasColumn('integration_logs', 'severity')) {
                $data['severity'] = fake()->randomElement(['info', 'warning', 'error']);
            }
            
            if (\Schema::hasColumn('integration_logs', 'message')) {
                $data['message'] = fake()->sentence();
            }
            
            if (\Schema::hasColumn('integration_logs', 'context')) {
                $data['context'] = json_encode(['additional_details' => fake()->sentence()]);
            }
            
            try {
                IntegrationLog::create($data);
            } catch (\Exception $e) {
                $this->command->error("Error creating log: " . $e->getMessage());
                // Try without the problematic status
                if (isset($data['status']) && $data['status'] === 'PENDING') {
                    $data['status'] = 'FAILED';
                    try {
                        IntegrationLog::create($data);
                    } catch (\Exception $innerE) {
                        $this->command->error("Still failed: " . $innerE->getMessage());
                    }
                }
            }
        }
    }
    
    /**
     * Get information about the status column constraints
     */
    protected function getStatusColumnInfo()
    {
        $columnInfo = ['type' => 'unknown', 'valid_values' => ['SUCCESS', 'FAILED']];
        
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $result = DB::select("SHOW COLUMNS FROM integration_logs WHERE Field = 'status'");
                
                if (!empty($result)) {
                    $columnType = $result[0]->Type;
                    
                    // Check if it's an ENUM
                    if (strpos($columnType, 'enum') === 0) {
                        $columnInfo['type'] = 'enum';
                        
                        // Extract the enum values
                        preg_match("/^enum\((.*)\)$/", $columnType, $matches);
                        if (isset($matches[1])) {
                            $enumValues = explode(',', $matches[1]);
                            $validValues = array_map(function($val) {
                                return trim($val, "'\"");
                            }, $enumValues);
                            
                            $columnInfo['valid_values'] = $validValues;
                        }
                    } else if (strpos($columnType, 'varchar') === 0 || strpos($columnType, 'char') === 0) {
                        $columnInfo['type'] = 'varchar';
                        // For varchar, we can use a wider range of values
                        $columnInfo['valid_values'] = ['SUCCESS', 'FAILED', 'PENDING', 'PROCESSING'];
                    }
                }
            }
        } catch (\Exception $e) {
            // If we can't determine the column type, use safe defaults
        }
        
        return $columnInfo;
    }
}
