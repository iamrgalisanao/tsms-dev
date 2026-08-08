<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedApplicationRolesAndPermissions();

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
            JobStatusSeeder::class,
            ValidationStatusSeeder::class,
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

    private function seedApplicationRolesAndPermissions(): void
    {
        $roleMetadata = [
            'admin' => ['Administrator', 'System Administrator'],
            'manager' => ['Manager', 'Operations manager'],
            'finance' => ['Finance', 'Finance reporting user'],
            'commercial' => ['Commercial', 'Commercial reporting user'],
            'vendor' => ['Vendor', 'Vendor license authority'],
            'vendor_support' => ['Vendor Support', 'Vendor support license authority'],
            'license_manager' => ['License Manager', 'License management authority'],
        ];

        foreach ($roleMetadata as $name => [$displayName, $description]) {
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ], [
                'description' => $description,
                'display_name' => $displayName,
                'is_system' => true,
            ]);
        }

        $licensePermissions = array_unique(array_filter([
            config('license.vendor_authority.manage_permission', 'license.manage'),
            ...array_values(config('license.vendor_authority.permissions', [])),
        ]));

        foreach ($licensePermissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        foreach (config('license.vendor_authority.roles', []) as $roleName) {
            try {
                $role = Role::findByName($roleName, 'web');
            } catch (RoleDoesNotExist $e) {
                Log::warning(
                    "DatabaseSeeder: license.vendor_authority.roles references undefined role '{$roleName}'; skipping permission grant."
                );
                continue;
            }

            $role->givePermissionTo($licensePermissions);
        }
    }
}
