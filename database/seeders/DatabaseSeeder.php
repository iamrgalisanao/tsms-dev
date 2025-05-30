<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\TransactionLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Always create admin user
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password123'),
                'role' => 'admin',
                'is_active' => 1,
                'tenant_id' => 1,
            ]
        );

        // In test environment, seed test data
        if (app()->environment('testing')) {
            $this->call(TestDataSeeder::class);
        }

         $this->call([
            // ...other seeders...
            TransactionLogSeeder::class,
            PosSampleDataSeeder::class,
            CircuitBreakerSeeder::class,
            TransactionSeeder::class,
            StoreHoursSeeder::class,
            StoreSeeder::class,
        ]);
    }
}