<?php

namespace Database\Seeders;

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

        // Always create admin user
        $adminUser = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password123'),
                'is_active' => 1,
            ]
        );

        // Assign the admin role using Spatie
        if (!$adminUser->hasRole('admin')) {
            $adminUser->assignRole('admin');
        }

        // In test environment, seed test data
        if (app()->environment('testing')) {
            $this->call(TestDataSeeder::class);
        }

        $this->call([
            // ...other seeders...
            // JobStatusSeeder::class,
            // ValidationStatusSeeder::class,
            // TransactionLogSeeder::class,
            // PosSampleDataSeeder::class,
            // CircuitBreakerSeeder::class,
            // TransactionSeeder::class,
            // StoreHoursSeeder::class,
            // StoreSeeder::class,
            // TransactionSchemaTestSeeder::class, // Add new test seeder for normalized schema
        ]);
    }
}