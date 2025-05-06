<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create system admin user
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@tsms.local',
            'password' => Hash::make('password'),
        ]);

        // Assign admin role (not super_admin)
        $admin->assignRole('admin');

        // Create test operator user
        $operator = User::create([
            'name' => 'Terminal Operator',
            'email' => 'operator@tsms.local',
            'password' => Hash::make('password'),
        ]);

        $operator->assignRole('operator');
    }
}