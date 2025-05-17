<?php

namespace Database\Seeders;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class PosSampleDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or find providers
        $this->createProviders();
        
        // Create terminals for each provider
        $this->createTerminals();
        
        // Generate statistics records
        $this->generateStatistics();
    }
    
    /**
     * Create sample POS providers
     */
    private function createProviders(): void
    {
        $providers = [
            [
                'name' => 'ABC Point of Sale, Inc.',
                'code' => 'ABC-POS',
                'api_key' => md5(uniqid('abc', true)),
                'description' => 'Major provider of retail point of sale systems with support for multi-tenant environments. Specializes in retail, grocery, and convenience store solutions.',
                'contact_email' => 'support@abcpos.example.com',
                'contact_phone' => '555-123-4567',
                'status' => 'active',
            ],
            [
                'name' => 'XYZ Restaurant Systems',
                'code' => 'XYZ-REST',
                'api_key' => md5(uniqid('xyz', true)),
                'description' => 'Restaurant POS solutions specializing in food service, table management, and kitchen display systems. Primarily serves casual dining and fast food establishments.',
                'contact_email' => 'help@xyzrest.example.com',
                'contact_phone' => '555-987-6543',
                'status' => 'active',
            ],
            [
                'name' => 'QuickSale Terminal Co.',
                'code' => 'QUICK-TERM',
                'api_key' => md5(uniqid('quick', true)),
                'description' => 'Affordable POS systems for small businesses with simplified operations. Focuses on ease of use and basic retail functionality for startups and small shops.',
                'contact_email' => 'service@quicksale.example.com',
                'contact_phone' => '555-456-7890',
                'status' => 'active',
            ],
            [
                'name' => 'EnterprisePOS Solutions',
                'code' => 'ENT-POS',
                'api_key' => md5(uniqid('ent', true)),
                'description' => 'Enterprise-grade point of sale systems with advanced inventory management, CRM integration, and multi-location support for large retail chains.',
                'contact_email' => 'enterprise@entpos.example.com',
                'contact_phone' => '555-789-0123',
                'status' => 'active',
            ],
            [
                'name' => 'MobilePay Systems',
                'code' => 'MOBILE-PAY',
                'api_key' => md5(uniqid('mobile', true)),
                'description' => 'Mobile-first POS solutions for pop-up shops, food trucks, and on-the-go businesses. Includes tablet and smartphone compatibility with cloud-based processing.',
                'contact_email' => 'info@mobilepay.example.com',
                'contact_phone' => '555-234-5678',
                'status' => 'active',
            ],
        ];
        
        foreach ($providers as $provider) {
            PosProvider::updateOrCreate(
                ['code' => $provider['code']],
                $provider
            );
        }
        
        $this->command->info('Created 5 POS providers');
    }
    
    /**
     * Create sample POS terminals
     */
    private function createTerminals(): void
    {
        // Get all providers and tenants
        $providers = PosProvider::all();
        $tenants = Tenant::all();
        
        // If no tenants exist, create a sample tenant
        if ($tenants->isEmpty()) {
            $tenant = Tenant::create([
                'name' => 'Sample Tenant',
                'code' => 'SAMPLE-001',
                'status' => 'active'
            ]);
            $tenants = collect([$tenant]);
        }
        
        $terminalCount = 0;
        
        // Check if machine_number column exists in pos_terminals table
        $hasMachineNumber = Schema::hasColumn('pos_terminals', 'machine_number');
        
        // Get valid status values from the database
        $validStatusValues = $this->getValidStatusValues('pos_terminals', 'status');
        $defaultStatus = 'active'; // Default fallback status
        
        // Create different number of terminals for each provider
        foreach ($providers as $provider) {
            // Determine how many terminals to create for this provider
            $numTerminals = rand(5, 20);
            
            // For each terminal, assign to a random tenant
            for ($i = 1; $i <= $numTerminals; $i++) {
                $tenant = $tenants->random();
                $terminalUid = $provider->code . '-TERM-' . str_pad($i, 3, '0', STR_PAD_LEFT);
                
                // Check if this tenant-terminal combination already exists
                $existingTerminal = PosTerminal::where('tenant_id', $tenant->id)
                    ->where('terminal_uid', $terminalUid)
                    ->first();
                
                if ($existingTerminal) {
                    // Skip this combination and try with another tenant
                    $this->command->info("Skipping duplicate terminal: {$terminalUid} for tenant {$tenant->id}");
                    continue;
                }
                
                $isActive = rand(0, 10) < 8; // 80% chance of being active
                
                // Calculate enrollment date - some recent, some older
                $daysAgo = rand(1, 180); // Up to ~6 months ago
                $enrolledAt = Carbon::now()->subDays($daysAgo);
                
                // For some terminals, make the registration date earlier than enrollment
                $registeredAt = rand(0, 10) < 3 ? 
                    $enrolledAt->copy()->subDays(rand(1, 5)) : 
                    $enrolledAt->copy();
                
                // Set status based on valid values
                if ($isActive) {
                    $status = in_array('active', $validStatusValues) ? 'active' : $defaultStatus;
                } else {
                    // Choose between inactive or other non-active status if available
                    if (in_array('inactive', $validStatusValues)) {
                        $status = 'inactive';
                    } else if (!empty($validStatusValues) && $validStatusValues[0] !== $defaultStatus) {
                        $status = $validStatusValues[0]; // Use first available status
                    } else {
                        $status = $defaultStatus;
                    }
                }
                
                // Build data array based on available columns
                $terminalData = [
                    'tenant_id' => $tenant->id,
                    'provider_id' => $provider->id,
                    'terminal_uid' => $terminalUid,
                    'registered_at' => $registeredAt,
                    'enrolled_at' => $enrolledAt,
                    'status' => $status,
                    'jwt_token' => 'sample_' . md5(uniqid($provider->code . $i, true)),
                ];
                
                // Only add machine_number if the column exists
                if ($hasMachineNumber) {
                    $terminalData['machine_number'] = rand(1, 100);
                }
                
                try {
                    // Create the terminal
                    PosTerminal::create($terminalData);
                    $terminalCount++;
                } catch (\Exception $e) {
                    $this->command->error("Error creating terminal: " . $e->getMessage());
                }
            }
        }
        
        $this->command->info("Created $terminalCount POS terminals across " . $providers->count() . " providers");
    }
    
    /**
     * Get valid values for an enum field
     */
    private function getValidStatusValues($table, $column)
    {
        try {
            // Check if the table and column exist
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                return ['active', 'inactive']; // Default values
            }
            
            // Try to get the column type information
            $columnInfo = DB::select("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'");
            
            if (empty($columnInfo)) {
                return ['active', 'inactive']; // Default values if column info not found
            }
            
            $columnType = $columnInfo[0]->Type;
            
            // Check if it's an ENUM type
            if (strpos($columnType, 'enum(') === 0) {
                // Extract values from enum('val1', 'val2', ...)
                preg_match_all("/'([^']+)'/", $columnType, $matches);
                return $matches[1] ?? ['active', 'inactive'];
            }
            
            // If it's not an enum, just return default values
            return ['active', 'inactive'];
            
        } catch (\Exception $e) {
            $this->command->error("Error fetching valid status values: " . $e->getMessage());
            return ['active', 'inactive']; // Default values on error
        }
    }
    
    /**
     * Generate statistics for each provider
     */
    private function generateStatistics(): void
    {
        $providers = PosProvider::all();
        $statCount = 0;
        
        // Check if the column exists before trying to insert data
        $hasInactiveTerminalCount = Schema::hasColumn('provider_statistics', 'inactive_terminal_count');
        $hasNewEnrollments = Schema::hasColumn('provider_statistics', 'new_enrollments');
        
        foreach ($providers as $provider) {
            // Generate statistics for the last 30 days
            for ($day = 0; $day < 30; $day++) {
                $date = Carbon::now()->subDays($day)->format('Y-m-d');
                
                // Calculate statistics based on terminals as of that date
                $totalTerminals = PosTerminal::where('provider_id', $provider->id)
                    ->where('enrolled_at', '<=', $date . ' 23:59:59')
                    ->count();
                    
                $activeTerminals = PosTerminal::where('provider_id', $provider->id)
                    ->where('enrolled_at', '<=', $date . ' 23:59:59')
                    ->where('status', 'active')
                    ->count();
                
                // Calculate new enrollments for that specific day
                $newEnrollments = PosTerminal::where('provider_id', $provider->id)
                    ->whereDate('enrolled_at', $date)
                    ->count();
                
                // Create the data array with columns that exist
                $statsData = [
                    'terminal_count' => $totalTerminals,
                    'active_terminal_count' => $activeTerminals,
                ];
                
                // Only add columns that exist in the table
                if ($hasInactiveTerminalCount) {
                    $statsData['inactive_terminal_count'] = $totalTerminals - $activeTerminals;
                }
                
                if ($hasNewEnrollments) {
                    $statsData['new_enrollments'] = $newEnrollments;
                }
                
                try {
                    // Create or update statistics record
                    ProviderStatistics::updateOrCreate(
                        ['provider_id' => $provider->id, 'date' => $date],
                        $statsData
                    );
                    
                    $statCount++;
                } catch (\Exception $e) {
                    $this->command->error("Error creating statistics record: " . $e->getMessage());
                    
                    // In case of error, try to extract the actual table structure from the database
                    $this->command->info("Trying to get actual table structure...");
                    $columns = Schema::getColumnListing('provider_statistics');
                    $this->command->info("Actual columns in provider_statistics: " . implode(', ', $columns));
                }
            }
        }
        
        $this->command->info("Generated $statCount statistics records");
    }
}