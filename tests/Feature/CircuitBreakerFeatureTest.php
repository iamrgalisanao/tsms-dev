<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CircuitBreakerFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant and user
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id
        ]);

        // Authenticate user
        Sanctum::actingAs($user);
        
        // Reset circuit breaker state
        $this->postJson('/api/v1/circuit-breakers/test-circuit/reset');
    }

    /** @test */
    public function example()
    {
        $response = $this->getJson('/api/v1/circuit-breakers/test-endpoint');
        $response->assertStatus(200);
    }

    /** @test */
    public function circuit_opens_after_failures()
    {
        // Reset circuit breaker state
        $this->postJson('/api/v1/circuit-breakers/test-circuit/reset');
        
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/circuit-breakers/test-circuit', [
                'should_fail' => true
            ]);
            $response->assertStatus(500);
        }

        $response = $this->postJson('/api/v1/circuit-breakers/test-circuit', [
            'should_fail' => false
        ]);
        $response->assertStatus(503);
    }

    /** @test */
    public function circuit_remains_closed_below_threshold()
    {
        // Reset circuit breaker state
        $this->postJson('/api/v1/circuit-breakers/test-circuit/reset');
        
        for ($i = 0; $i < 2; $i++) {
            $response = $this->postJson('/api/v1/circuit-breakers/test-circuit', [
                'should_fail' => true
            ]);
            $response->assertStatus(500);
        }

        $response = $this->postJson('/api/v1/circuit-breakers/test-circuit', [
            'should_fail' => false
        ]);
        $response->assertStatus(200);
    }
}