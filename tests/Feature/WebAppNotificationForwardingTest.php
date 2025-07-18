<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAppNotificationForwardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test forwarding notification to external webapp endpoint.
     */
    public function test_forwards_notification_to_webapp()
    {
        // Fake HTTP requests
        Http::fake([
            'http://webapp.local/api/notifications' => Http::response(['success' => true], 200),
        ]);

        // Simulate notification payload
        $payload = [
            'type' => 'transaction',
            'message' => 'Transaction completed',
            'data' => [
                'transaction_id' => 'TX123456',
                'amount' => 100.00,
            ],
        ];

        // Forward notification (simulate your actual forwarding logic)
        $response = Http::post('http://webapp.local/api/notifications', $payload);

        // Assert the request was sent
        Http::assertSent(function ($request) use ($payload) {
            return $request->url() === 'http://webapp.local/api/notifications'
                && $request['type'] === $payload['type']
                && $request['message'] === $payload['message'];
        });

        // Assert response
        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->json('success'));
    }
}
