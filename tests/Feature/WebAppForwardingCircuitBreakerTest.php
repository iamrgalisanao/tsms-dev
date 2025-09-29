<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\Transaction;
use App\Models\Tenant;
use App\Models\Terminal;
use App\Services\WebAppForwardingService;
use Carbon\Carbon;

class WebAppForwardingCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransaction(array $overrides = []): Transaction
    {
        $tenant = Tenant::factory()->create(['name' => null]);
        $terminal = Terminal::factory()->create();

        return Transaction::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) \Str::uuid(),
            'gross_sales' => 465.00,
            'net_sales' => 415.18,
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'submission_uuid' => (string) \Str::uuid(),
            'transaction_timestamp' => Carbon::now(),
            'submission_timestamp' => Carbon::now(),
        ], $overrides));
    }

    public function test_http_422_does_not_increment_circuit_breaker(): void
    {
        config()->set('tsms.web_app.endpoint', 'https://example.test/webapp');
        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.threshold', 2);

        $tx = $this->makeTransaction();

        Http::fake([
            'https://example.test/webapp' => Http::response(['error' => 'validation'], 422)
        ]);

        $svc = app(WebAppForwardingService::class);
        $result = $svc->forwardTransactionImmediately($tx);

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP_422_VALIDATION', $result['classification'] ?? null);
        $this->assertEquals(0, Cache::get('webapp_forwarding_circuit_breaker_failures', 0));
    }

    public function test_http_500_increments_circuit_breaker(): void
    {
        config()->set('tsms.web_app.endpoint', 'https://example.test/webapp');
        config()->set('tsms.circuit_breaker.enabled', true);
        config()->set('tsms.circuit_breaker.threshold', 2);

        $tx = $this->makeTransaction();

        Http::fake([
            'https://example.test/webapp' => Http::response(['error' => 'server'], 500)
        ]);

        $svc = app(WebAppForwardingService::class);
        $result = $svc->forwardTransactionImmediately($tx);

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP_5XX_RETRYABLE', $result['classification'] ?? null);
        $this->assertEquals(1, Cache::get('webapp_forwarding_circuit_breaker_failures', 0));
    }
}
