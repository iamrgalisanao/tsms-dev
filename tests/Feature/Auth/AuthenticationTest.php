<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\AuthTestHelpers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

use Spatie\Permission\Models\Role;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions, AuthTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up auth test environment with mocked cookie service
        $this->setUpAuthTestEnvironment();
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        // Create a test user with known credentials
        $this->createTestUser();
        
        $this->withoutMiddleware(\App\Http\Middleware\EnsureDashboardAuth::class);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'roles',
                ]
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        // Create a user with our helper
        $user = $this->createTestUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'email'
                ]
            ]);
    }

    public function test_user_can_logout(): void
    {
        // Use our helper to create and authenticate a user
        $this->actAsAuthenticatedUser();

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully'
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_can_get_authenticated_user_details(): void
    {
        // Create a user and get a token
        $user = $this->createTestUser();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/user');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'email',
                    'roles'
                ]
            ]);
    }

    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        $response = $this->getJson('/api/web/circuit-breaker/metrics');

        $response->assertUnauthorized();
    }

    public function test_invalid_token_cannot_access_protected_routes(): void
    {
        $response = $this->withToken('invalid-token')
            ->getJson('/api/web/circuit-breaker/metrics');

        $response->assertUnauthorized();
    }
    
    protected function tearDown(): void
    {
        $this->tearDownAuthTestEnvironment();
        parent::tearDown();
    }
}