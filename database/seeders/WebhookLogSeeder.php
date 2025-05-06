<?php

namespace Database\Seeders;

use App\Models\WebhookLog;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WebhookLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();

        // Successful webhook delivery
        WebhookLog::create([
            'tenant_id' => $tenant->id,
            'webhook_id' => Str::uuid(),
            'event_type' => 'transaction.created',
            'payload' => [
                'transaction_id' => Str::uuid(),
                'amount' => 1500.00,
                'status' => 'completed',
                'created_at' => Carbon::now()->toIso8601String()
            ],
            'endpoint_url' => 'https://api.merchant.com/webhooks/transactions',
            'http_status' => 200,
            'response_body' => json_encode([
                'status' => 'received',
                'message' => 'Webhook processed successfully'
            ]),
            'response_headers' => [
                'Content-Type' => 'application/json',
                'X-Request-Id' => Str::uuid()
            ],
            'processing_time' => 245,
            'status' => 'DELIVERED',
            'attempt_count' => 1,
            'source_ip' => '192.168.1.100',
            'created_at' => Carbon::now()
        ]);

        // Failed webhook with retry
        WebhookLog::create([
            'tenant_id' => $tenant->id,
            'webhook_id' => Str::uuid(),
            'event_type' => 'transaction.refunded',
            'payload' => [
                'transaction_id' => Str::uuid(),
                'refund_amount' => 500.00,
                'reason' => 'customer_request',
                'created_at' => Carbon::now()->subHours(1)->toIso8601String()
            ],
            'endpoint_url' => 'https://api.merchant.com/webhooks/transactions',
            'http_status' => 503,
            'response_body' => 'Service Temporarily Unavailable',
            'response_headers' => [
                'Retry-After' => '300'
            ],
            'processing_time' => 5000,
            'status' => 'PENDING_RETRY',
            'attempt_count' => 2,
            'max_attempts' => 5,
            'next_retry_at' => Carbon::now()->addMinutes(5),
            'last_error' => 'Service temporarily unavailable',
            'source_ip' => '192.168.1.100',
            'created_at' => Carbon::now()->subMinutes(30)
        ]);

        // Permanently failed webhook
        WebhookLog::create([
            'tenant_id' => $tenant->id,
            'webhook_id' => Str::uuid(),
            'event_type' => 'transaction.failed',
            'payload' => [
                'transaction_id' => Str::uuid(),
                'error_code' => 'INSUFFICIENT_FUNDS',
                'created_at' => Carbon::now()->subHours(2)->toIso8601String()
            ],
            'endpoint_url' => 'https://api.merchant.com/webhooks/transactions',
            'http_status' => 404,
            'response_body' => 'Endpoint not found',
            'response_headers' => [],
            'processing_time' => 150,
            'status' => 'FAILED',
            'attempt_count' => 5,
            'max_attempts' => 5,
            'last_error' => 'Endpoint not found after maximum retry attempts',
            'source_ip' => '192.168.1.100',
            'created_at' => Carbon::now()->subHours(1)
        ]);
    }
}
