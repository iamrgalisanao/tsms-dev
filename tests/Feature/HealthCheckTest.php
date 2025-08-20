<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the health check endpoint returns correct structure.
     *
     * @return void
     */
    public function test_health_check_endpoint()
    {
        $response = $this->get('/api/v1/health');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'timestamp',
                    'version'
                ])
                ->assertJson([
                    'status' => 'ok',
                    'version' => '1.0.0'
                ]);
    }

    /**
     * Test health check doesn't require authentication.
     *
     * @return void
     */
    public function test_health_check_no_auth_required()
    {
        $response = $this->get('/api/v1/health');
        
        // Should not return 401 Unauthorized
        $this->assertNotEquals(401, $response->status());
        $response->assertStatus(200);
    }

    /**
     * Test health check returns proper JSON content type.
     *
     * @return void
     */
    public function test_health_check_content_type()
    {
        $response = $this->get('/api/v1/health');
        
        $response->assertStatus(200)
                ->assertHeader('content-type', 'application/json');
    }
}
