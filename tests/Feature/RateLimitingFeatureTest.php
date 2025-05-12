<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Traits\AuthTestHelpers;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RateLimitingFeatureTest extends TestCase
{
    use RefreshDatabase, AuthTestHelpers;

    private const CIRCUIT_BREAKER_ENDPOINT = '/api/web/circuit-breaker/states';

    protected function setUp(): void
    {
        parent::setUp();

        // Set up auth test environment (includes cookie mock)
        $this->setUpAuthTestEnvironment();

        // Configure security logging for tests
        Config::set('logging.channels.security', [
            'driver' => 'single',
            'path' => storage_path('logs/security-test.log'),
            'level' => 'debug',
        ]);

        // Configure Redis for tests to use predis instead of phpredis
        Config::set('redis.client', 'predis');
        
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
            $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
            $response->assertStatus(200);
            $response->assertHeader('X-RateLimit-Remaining');
        }
    }

    /** @test */
    public function it_blocks_requests_when_rate_limit_exceeded()
    {
        // Make more requests than allowed
        for ($i = 0; $i < 35; $i++) {
            $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
            
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
            $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
        }
        
        // Next request from first tenant should be blocked
        $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
        $response->assertStatus(429);

        // Switch to second tenant
        Sanctum::actingAs($user2);
        
        // Request from second tenant should work
        $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
        $response->assertStatus(200);
    }

    /** @test */
    public function it_works_alongside_circuit_breaker()
    {
        // Make sure we're using a valid route for testing
        $this->withHeader('X-Tenant-ID', 'test-tenant-1');

        // First make standard request to test rate limiting headers work
        $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');

        // Check rate limiting continues to work after multiple requests
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson(self::CIRCUIT_BREAKER_ENDPOINT);
            $response->assertStatus(200);
            $response->assertHeader('X-RateLimit-Remaining');
        }
    }
    
    protected function tearDown(): void
    {
        $this->tearDownAuthTestEnvironment();
        parent::tearDown();
    }
}