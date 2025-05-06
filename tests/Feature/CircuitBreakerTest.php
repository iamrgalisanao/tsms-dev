<?php

namespace Tests\Feature;

use Tests\TestCase;  // Correct import
use App\Models\User;
use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id
        ]);
        
        Sanctum::actingAs($user);
    }

    /** @test */
    public function test_example(): void
    {
        $response = $this->getJson('/api/v1/circuit-breakers/test');
        $response->assertStatus(200);
    }
}
