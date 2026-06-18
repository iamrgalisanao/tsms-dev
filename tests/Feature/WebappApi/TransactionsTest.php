<?php

namespace Tests\Feature\WebappApi;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication()
    {
    $response = $this->getJson('/api/v1/webapp/transactions');
        $response->assertStatus(401);
    }

    public function test_token_with_ability_can_access_transactions()
    {
    $user = User::factory()->create([ 'email' => 'webapp-tx@example.test' ]);

        $token = $user->createToken('webapp-test-token', ['webapp:read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
    ])->getJson('/api/v1/webapp/transactions');

        // Should be successful (200) and return JSON structure
        $response->assertStatus(200)->assertJsonStructure([ 'data', 'links', 'meta' ]);
    }
}
