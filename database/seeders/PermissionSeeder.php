<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define permissions
        $permissions = [
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.delete',
            'circuit-breakers.view',
            'circuit-breakers.create',
            'circuit-breakers.edit',
            'circuit-breakers.delete'
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Get or create roles
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $operatorRole = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $terminalRole = Role::firstOrCreate(['name' => 'terminal', 'guard_name' => 'web']);
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        // Assign permissions to roles
        $adminRole->givePermissionTo($permissions);
        $operatorRole->givePermissionTo([
            'users.view',
            'circuit-breakers.view',
            'circuit-breakers.edit'
        ]);
        $terminalRole->givePermissionTo([
            'circuit-breakers.view'
        ]);
        $viewerRole->givePermissionTo([
            'users.view',
            'circuit-breakers.view'
        ]);
    }
}
