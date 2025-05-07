<?php

namespace Tests\Traits;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait WithTestUser
{
    protected function createAuthenticatedUser(array $attributes = [], array $roles = ['test_user'])
    {
        // Create a temporary test role if it doesn't exist
        if (!Role::where('name', 'test_user')->exists()) {
            Role::create(['name' => 'test_user', 'guard_name' => 'web']);
        }
        
        $user = User::factory()->create($attributes);
        $user->assignRole($roles);
        
        return $user;
    }

    protected function actingAsUser(array $attributes = [], array $roles = ['user'])
    {
        $user = $this->createAuthenticatedUser($attributes, $roles);
        Sanctum::actingAs($user);
        
        return $user;
    }
}
