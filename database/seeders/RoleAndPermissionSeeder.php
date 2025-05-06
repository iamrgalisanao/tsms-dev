<?php

namespace Database\Seeders;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create permissions
        $permissions = [
            'view_tenants',
            'manage_tenants',
            'view_terminals',
            'manage_terminals',
            'view_transactions',
            'manage_transactions',
            'view_logs',
            'manage_users',
            'manage_roles',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions
        $roles = [
            'super_admin' => $permissions,
            'admin' => [
                'view_tenants',
                'view_terminals',
                'manage_terminals',
                'view_transactions',
                'view_logs',
            ],
            'operator' => [
                'view_terminals',
                'view_transactions',
                'view_logs',
            ],
        ];

        foreach ($roles as $role => $permissions) {
            $role = Role::create(['name' => $role]);
            $role->syncPermissions($permissions);
        }
    }
}