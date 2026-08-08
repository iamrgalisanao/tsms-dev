<?php

namespace Tests\Unit;

use App\Models\User;
use App\Providers\HorizonServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HorizonGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'ops', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $provider = new HorizonServiceProvider(app());
        $method = new \ReflectionMethod($provider, 'gate');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    public function test_horizon_gate_allows_real_spatie_admin_and_ops_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $ops = User::factory()->create();
        $ops->assignRole('ops');

        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($ops)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($manager)->allows('viewHorizon'));
    }

    public function test_horizon_gate_supports_role_column_fallback(): void
    {
        $admin = User::factory()->create();
        $admin->setAttribute('role', 'admin');

        $ops = User::factory()->create();
        $ops->setAttribute('role', 'ops');

        $manager = User::factory()->create();
        $manager->setAttribute('role', 'manager');

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertTrue(Gate::forUser($ops)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($manager)->allows('viewHorizon'));
    }

    public function test_horizon_gate_denies_null_user_and_role_check_exceptions(): void
    {
        $throwingUser = new class extends User {
            public function hasAnyRole(...$roles): bool
            {
                throw new \RuntimeException('role backend unavailable');
            }
        };

        $this->assertFalse(Gate::allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($throwingUser)->allows('viewHorizon'));
    }
}
