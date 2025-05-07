<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class RateLimitingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_endpoint_is_rate_limited(): void
    {
        // Attempt to login multiple times
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password'
            ]);
        }

        // The 6th attempt should be rate limited
        $response->assertStatus(429);
        $response->assertJsonStructure(['message', 'retry_after_seconds']);
    }

    public function test_api_endpoints_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Make multiple requests to an API endpoint
        for ($i = 0; $i < 61; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/web/dashboard/transactions');
        }

        // The 61st request should be rate limited
        $response->assertStatus(429);
    }

    public function test_circuit_breaker_endpoints_have_separate_rate_limits(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Make multiple requests to a circuit breaker endpoint
        for ($i = 0; $i < 31; $i++) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->withHeader('X-Tenant-ID', 'test-tenant')
                ->getJson('/api/web/circuit-breaker/metrics');
        }

        // The 31st request should be rate limited
        $response->assertStatus(429);
    }
}
