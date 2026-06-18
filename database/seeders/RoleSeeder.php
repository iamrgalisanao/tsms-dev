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
                'name' => 'commercial',
                'guard_name' => 'web',
                'description' => 'Access to commercial reports and dashboards'
            ],
            [
                'name' => 'finance',
                'guard_name' => 'web',
                'description' => 'Access to finance reports and exports'
            ],
            [
                'name' => 'tenants',
                'guard_name' => 'web',
                'description' => 'Read-only access to dashboards'
            ]
        ];

        // Create or update roles (idempotent)
        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name'], 'guard_name' => $role['guard_name']],
                ['description' => $role['description'] ?? null]
            );
        }
    }
}
