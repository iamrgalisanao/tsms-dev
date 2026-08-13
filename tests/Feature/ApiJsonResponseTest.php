<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiJsonResponseTest extends TestCase
{
    public function test_api_auth_failures_return_json_without_json_accept_header(): void
    {
        $response = $this->withHeaders([
            'Accept' => '*/*',
        ])->get('/api/v1/auth/me');

        $response
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_missing_api_routes_return_json_without_json_accept_header(): void
    {
        $response = $this->withHeaders([
            'Accept' => '*/*',
        ])->post('/api/v1/not-a-real-endpoint');

        $this->assertTrue(
            $response->status() >= 400 && $response->status() < 500,
            'Expected a 4xx response, received '.$response->status().'.'
        );

        $response->assertHeader('Content-Type', 'application/json');

        $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getContent());
    }
}
