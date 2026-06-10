<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        foreach (['admin', 'finance', 'commercial', 'manager', 'tenant'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function test_unauthenticated_user_cannot_access_reconciliation(): void
    {
        $response = $this->postJson('/api/transactions/logs/reconcile');

        $response->assertStatus(401);
    }

    /** @test */
    public function test_unauthorized_roles_cannot_access_reconciliation(): void
    {
        foreach (['manager', 'tenant'] as $roleName) {
            $user = User::factory()->create();
            $user->assignRole($roleName);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/transactions/logs/reconcile');

            $response->assertStatus(403);
        }
    }

    /** @test */
    public function test_authorized_roles_can_access_reconciliation(): void
    {
        foreach (['admin', 'finance', 'commercial'] as $roleName) {
            $user = User::factory()->create();
            $user->assignRole($roleName);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/transactions/logs/reconcile');

            $response->assertStatus(200);
            $response->assertJson([
                'status' => 'success',
            ]);
            $response->assertJsonStructure([
                'status',
                'message',
                'details' => [
                    'stranded',
                    'repair',
                ]
            ]);
        }
    }
}
