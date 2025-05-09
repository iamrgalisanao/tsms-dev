<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Redis;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RateLimitingFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any existing rate limits
        Redis::flushdb();

        // Create tenant and user
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id
        ]);

        // Authenticate user
        Sanctum::actingAs($user);
    }

    /** @test */
    public function it_allows_requests_within_rate_limit()
    {
        // Make requests within the rate limit (default 30 per minute for circuit_breaker)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson('/api/web/circuit-breaker/states');
            $response->assertStatus(200);
            $response->assertHeader('X-RateLimit-Remaining');
        }
    }

    /** @test */
    public function it_blocks_requests_when_rate_limit_exceeded()
    {
        // Make more requests than allowed
        for ($i = 0; $i < 35; $i++) {
            $response = $this->getJson('/api/web/circuit-breaker/states');
            
            if ($i < 30) {
                $response->assertStatus(200);
            } else {
                $response->assertStatus(429);
                $response->assertJsonStructure(['error', 'message']);
                break;
            }
        }
    }

    /** @test */
    public function it_tracks_rate_limits_per_tenant()
    {
        // Create a second tenant and user
        $tenant2 = Tenant::factory()->create();
        $user2 = User::factory()->create([
            'tenant_id' => $tenant2->id
        ]);

        // Make requests as first tenant
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/web/circuit-breaker/states');
        }
        
        // Next request from first tenant should be blocked
        $response = $this->getJson('/api/web/circuit-breaker/states');
        $response->assertStatus(429);

        // Switch to second tenant
        Sanctum::actingAs($user2);
        
        // Request from second tenant should work
        $response = $this->getJson('/api/web/circuit-breaker/states');
        $response->assertStatus(200);
    }

    /** @test */
    public function it_works_alongside_circuit_breaker()
    {
        // Test circuit breaker functionality
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/web/circuit-breaker/test-circuit', [
                'should_fail' => true
            ]);
            $response->assertStatus(500);
        }

        // Circuit should now be open
        $response = $this->postJson('/api/web/circuit-breaker/test-circuit', [
            'should_fail' => false
        ]);
        $response->assertStatus(503); // Circuit breaker response

        // Rate limit headers should still be present
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }
}
