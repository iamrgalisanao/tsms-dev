<?php

namespace Tests\Traits;

use App\Models\Tenant;
use App\Models\User;

trait TestHelpers
{
    protected function createTestTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Test Tenant',
            'code' => 'TEST'
        ]);
    }

    protected function createTestUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id
        ]);
    }    protected function setupTestEnvironment(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTestUser($tenant);
        // Don't attempt to log in the user during tests
        // $this->actingAs($user);
    }
}
