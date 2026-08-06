<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederRoleProvisioningTest extends TestCase
{
    public function test_license_role_permission_grant_skips_unknown_configured_roles(): void
    {
        config(['license.vendor_authority.roles' => ['vendor', 'nonexistent_role_xyz']]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'nonexistent_role_xyz'));

        $seeder = new DatabaseSeeder();
        $method = new \ReflectionMethod($seeder, 'seedApplicationRolesAndPermissions');
        $method->setAccessible(true);
        $method->invoke($seeder);

        $vendorRole = Role::findByName('vendor', 'web');

        $this->assertTrue($vendorRole->hasPermissionTo('license.manage'));
        $this->assertTrue(Permission::where('name', 'license.view')->exists());
    }
}
