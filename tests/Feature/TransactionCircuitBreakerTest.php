<?php


namespace Tests\Feature;

use App\Models\CircuitBreaker;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Carbon\Carbon;
use Tests\Traits\AuthTestHelpers;
use Tymon\JWTAuth\Facades\JWTAuth;

class TransactionCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;
    use AuthTestHelpers;

    protected $terminal;
    protected $token;
    protected $headers;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up auth test environment (includes cookie mock)
        $this->setUpAuthTestEnvironment();
        
        // Configure security logging for tests
        config(['logging.channels.security' => [
            'driver' => 'single',
            'path' => storage_path('logs/security-test.log'),
            'level' => 'debug',
        ]]);
        
        // Create tenant and terminal
        $tenant = Tenant::factory()->create();
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'retry_enabled' => true
        ]);
        
        // Generate JWT token for authentication
        $this->terminal->tenant_id = $tenant->id; // Ensure tenant ID is set
        $this->token = JWTAuth::fromUser($this->terminal);
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Tenant-ID' => $tenant->id // Add tenant ID to headers
        ];
    }

    /** @test */
    public function circuit_breaker_trips_after_multiple_failures()
    {
        // Create a circuit breaker with a low threshold
        $circuitBreaker = CircuitBreaker::forService('api.transactions', $this->terminal->tenant_id);
        $circuitBreaker->failure_threshold = 3;
        $circuitBreaker->save();
        
        // Simulate multiple failed requests by manually incrementing failure count
        // (In a real test, you'd make actual failing API calls)
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        
        // Verify circuit is still closed
        $this->assertEquals(CircuitBreaker::STATUS_CLOSED, $circuitBreaker->status);
        
        // One more failure should trip the circuit
        $circuitBreaker->recordFailure();
        $this->assertEquals(CircuitBreaker::STATUS_OPEN, $circuitBreaker->status);
        
        // Verify that requests are now blocked
        $this->assertFalse($circuitBreaker->isAllowed());
    }

    /** @test */
    public function api_returns_503_when_circuit_is_open()
    {
        // Create and trip a circuit breaker
        $circuitBreaker = CircuitBreaker::forService('api.transactions', $this->terminal->tenant_id);
        $circuitBreaker->status = CircuitBreaker::STATUS_OPEN;
        $circuitBreaker->cooldown_until = Carbon::now()->addMinutes(5);
        $circuitBreaker->save();
        
        // Debug output
        \Illuminate\Support\Facades\Log::info('Circuit breaker status check: ' . json_encode([
            'id' => $circuitBreaker->id,
            'status' => $circuitBreaker->status,
            'tenant_id' => $circuitBreaker->tenant_id,
            'name' => $circuitBreaker->name,
            'is_open' => $circuitBreaker->status === CircuitBreaker::STATUS_OPEN,
            'cooldown_until' => $circuitBreaker->cooldown_until,
        ]));
        
        // Create 10 failed logs to trigger circuit breaker in controller
        for ($i = 0; $i < 11; $i++) {
            \App\Models\IntegrationLog::factory()->create([
                'status' => 'FAILED',
                'created_at' => Carbon::now()->subMinutes(3)
            ]);
        }
        
        // Make a request that should be blocked by circuit breaker
        $response = $this->withHeaders($this->headers)
            ->postJson('/api/v1/transactions', [
                'transaction_id' => $this->faker->uuid,
                'hardware_id' => $this->faker->uuid,
                'transaction_timestamp' => Carbon::now()->toIso8601String(),
                'gross_sales' => 100.00
            ]);
        
        // Assert we get a 503 Service Unavailable
        $response->assertStatus(503);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonStructure(['retry_at']);
    }
    
    protected function tearDown(): void
    {
        $this->tearDownAuthTestEnvironment();
        parent::tearDown();
    }
}