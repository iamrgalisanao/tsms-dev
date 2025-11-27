<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use App\Models\RbacAudit;

class RbacAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_assignment_generates_audit_record()
    {
        // seed roles/permissions
        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']);

        // create user who will act (admin)
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        // create target user
        $target = User::factory()->create();

        // act as actor and assign role to target
        $this->actingAs($actor)
            ->call('POST', '/users', [
                'name' => 'Bob',
                'email' => 'bob@example.com',
                'password' => 'password123',
                'roles' => ['tenant'],
            ]);

        // Alternatively trigger assignRole directly under auth context
        $target->assignRole('tenant');

        // assert an audit entry exists for RoleAttached or similar
        $audit = RbacAudit::where('target_name', 'tenant')->first();
        $this->assertNotNull($audit, 'Expected an RBAC audit record for role assignment');
        $this->assertEquals('tenant', $audit->target_name);
    }
}
