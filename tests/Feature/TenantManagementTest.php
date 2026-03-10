<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_can_list_tenants()
    {
        Tenant::factory()->count(3)->create();

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/api/tenants');

        $response->assertStatus(200)
            ->assertJsonCount(4);
    }

    public function test_can_create_tenant()
    {
        $user = User::factory()->create();
        $data = [
            'trade_name' => 'New Test Tenant',
            'customer_code' => 'T1001',
            'company_id' => 1,
            'status' => 'Operational',
        ];

        $response = $this->actingAs($user)->postJson('/api/tenants', $data);

        $response->assertStatus(201)
            ->assertJsonPath('trade_name', 'New Test Tenant');

        $this->assertDatabaseHas('tenants', ['trade_name' => 'New Test Tenant']);
    }

    public function test_can_manage_tenant_users()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();

        // Create tenant user
        $userData = [
            'name' => 'Tenant Staff',
            'email' => 'staff@tenant.com',
            'password' => 'password123',
        ];

        $response = $this->actingAs($user)->postJson("/api/tenants/{$tenant->id}/users", $userData);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Tenant Staff');

        $tenantUser = User::where('email', 'staff@tenant.com')->first();
        $this->assertEquals($tenant->id, $tenantUser->tenant_id);

        // List tenant users
        $response = $this->actingAs($user)->getJson("/api/tenants/{$tenant->id}/users");
        $response->assertStatus(200)->assertJsonCount(1);
    }
}
