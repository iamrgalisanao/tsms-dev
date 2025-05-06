<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define core system roles
        $roles = [
            [
                'name' => 'admin',
                'guard_name' => 'web',
                'description' => 'System Administrator with full access'
            ],
            [
                'name' => 'operator',
                'guard_name' => 'web',
                'description' => 'Terminal Operator with limited access'
            ],
            [
                'name' => 'terminal',
                'guard_name' => 'web',
                'description' => 'POS Terminal Service Account'
            ],
            [
                'name' => 'viewer',
                'guard_name' => 'web',
                'description' => 'Read-only access to dashboards'
            ]
        ];

        // Create roles
        foreach ($roles as $role) {
            Role::create($role);
        }
    }
}
