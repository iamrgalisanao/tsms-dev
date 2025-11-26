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

        // Ensure required roles exist for TSMS Webapp
        // Standard canonical role names (lowercase) used across the app: admin, finance, commercial, tenant
        $roles = [
            'admin' => [
                // admin gets all permissions by default
                'permissions' => $permissions,
            ],
            'finance' => [
                'permissions' => [
                    'users.view',
                    'circuit-breakers.view',
                    // finance-specific permissions (extend as needed)
                ],
            ],
            'commercial' => [
                'permissions' => [
                    'users.view',
                    'circuit-breakers.view',
                    // commercial-specific permissions (extend as needed)
                ],
            ],
            'tenant' => [
                'permissions' => [
                    'circuit-breakers.view',
                ],
            ],
        ];

        foreach ($roles as $roleName => $meta) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ], [
                'description' => ucfirst($roleName) . ' role',
                'display_name' => ucfirst($roleName),
                'is_system' => in_array($roleName, ['admin']),
            ]);

            if (!empty($meta['permissions'])) {
                $role->givePermissionTo($meta['permissions']);
            }
        }
    }
}
