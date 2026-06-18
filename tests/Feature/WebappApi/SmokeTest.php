<?php

namespace Tests\Feature\WebappApi;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    /**
     * Lightweight smoke test for Webapp API; skips when smoke token is not provided.
     */
    public function test_transactions_count_smoke()
    {
        $token = env('WEBAPP_API_SMOKE_TOKEN');
        if (! $token) {
            $this->markTestSkipped('WEBAPP_API_SMOKE_TOKEN not set; skip smoke test');
            return;
        }

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/webapp/transactions/count');

        $resp->assertStatus(200);
        $resp->assertJsonStructure(['count']);
    }
}
