<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ensure the admin role exists
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ], [
            'description' => 'System Administrator',
            'display_name' => 'Administrator',
            'is_system' => true,
        ]);

        // Call the admin user seeder
        // $this->call(AdminUserSeeder::class);

        // In test environment, seed test data
        // if (app()->environment('testing')) {
        //     $this->call(TestDataSeeder::class);
        // }

        $this->call([
            // Core data - run these first
            // CompanySeeder::class,           // Import companies from CSV
            // TenantSeeder::class,           // Import tenants from CSV (depends on companies)
            
            // Schema-specific seeders
            //TransactionSchemaTestSeeder::class, // Job statuses, validation statuses, etc.
            
            // Optional test data seeders (uncomment as needed)
            // RetryTransactionSeeder::class, // Create retry test data
            // TestDataSeeder::class,
            // JobStatusSeeder::class,
            // ValidationStatusSeeder::class,
            // TransactionLogSeeder::class,
            // PosSampleDataSeeder::class,
            // CircuitBreakerSeeder::class,
            // TransactionSeeder::class,
            // StoreHoursSeeder::class,
            // StoreSeeder::class,
            AdminUserSeeder::class, // Always create admin user
            CompanySeeder::class, // Import companies from CSV
            TenantSeeder::class, // Import tenants from CSV (depends on companies)
            ReferenceTablesSeeder::class, // Seed reference tables like pos_types, integration_types, etc
            PosTerminalSeeder::class, // Seed POS terminals
        ]);
    }
}