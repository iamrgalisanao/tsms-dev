<?php


namespace Tests\Unit;

use App\Models\CircuitBreaker;
use App\Models\Tenant;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create tenants manually for tests
        $tenant1 = new Tenant();
        $tenant1->id = 1;
        $tenant1->name = 'Test Tenant 1';
        $tenant1->code = 'TEST1'; // Add required code field
        $tenant1->save();
        
        // Create a second tenant for multi-tenant tests
        $tenant2 = new Tenant();
        $tenant2->id = 2;
        $tenant2->name = 'Test Tenant 2';
        $tenant2->code = 'TEST2'; // Add required code field
        $tenant2->save();
    }

    /** @test */
    public function it_starts_in_closed_state_when_created()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $circuitBreaker->state);
        $this->assertEquals(0, $circuitBreaker->failure_count);
        $this->assertEquals(5, $circuitBreaker->failure_threshold);
        $this->assertEquals(300, $circuitBreaker->reset_timeout);
    }

    /** @test */
    public function it_increments_failure_count_when_failure_recorded()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        
        $circuitBreaker->recordFailure();
        $this->assertEquals(1, $circuitBreaker->failure_count);
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $circuitBreaker->state);
        
        $circuitBreaker->recordFailure();
        $this->assertEquals(2, $circuitBreaker->failure_count);
    }

    /** @test */
    public function it_opens_circuit_when_failures_reach_threshold()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->failure_threshold = 3;
        $circuitBreaker->save();
        
        // Record failures up to threshold
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure();
        $circuitBreaker->recordFailure(); // This should trip the circuit
        
        // Refresh from database
        $circuitBreaker = CircuitBreaker::find($circuitBreaker->id);
        
        $this->assertEquals(CircuitBreaker::STATE_OPEN, $circuitBreaker->state);
        $this->assertEquals(3, $circuitBreaker->failure_count);
        $this->assertNotNull($circuitBreaker->opened_at);
        $this->assertNotNull($circuitBreaker->cooldown_until);
        $this->assertTrue($circuitBreaker->cooldown_until->isFuture());
    }

    /** @test */
    public function it_blocks_requests_when_circuit_is_open()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->state = CircuitBreaker::STATE_OPEN;
        $circuitBreaker->cooldown_until = Carbon::now()->addMinutes(5);
        $circuitBreaker->save();
        
        $this->assertFalse($circuitBreaker->isAllowed());
    }

    /** @test */
    public function it_transitions_to_half_open_when_cooldown_period_passes()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->state = CircuitBreaker::STATE_OPEN;
        $circuitBreaker->cooldown_until = Carbon::now()->subMinute(); // Cooldown has passed
        $circuitBreaker->save();
        
        // This should update the state to half-open
        $isAllowed = $circuitBreaker->isAllowed();
        
        // Refresh from database
        $circuitBreaker = CircuitBreaker::find($circuitBreaker->id);
        
        $this->assertTrue($isAllowed);
        $this->assertEquals(CircuitBreaker::STATE_HALF_OPEN, $circuitBreaker->state);
    }

    /** @test */
    public function it_allows_requests_in_half_open_state()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->state = CircuitBreaker::STATE_HALF_OPEN;
        $circuitBreaker->save();
        
        $this->assertTrue($circuitBreaker->isAllowed());
    }

    /** @test */
    public function it_closes_circuit_after_success_in_half_open_state()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->state = CircuitBreaker::STATE_HALF_OPEN;
        $circuitBreaker->failure_count = 3;
        $circuitBreaker->save();
        
        $circuitBreaker->recordSuccess();
        
        // Refresh from database
        $circuitBreaker = CircuitBreaker::find($circuitBreaker->id);
        
        $this->assertEquals(CircuitBreaker::STATE_CLOSED, $circuitBreaker->state);
        $this->assertEquals(0, $circuitBreaker->failure_count);
    }

    /** @test */
    public function it_resets_failure_count_on_success()
    {
        $circuitBreaker = CircuitBreaker::forService('api.transactions', 1);
        $circuitBreaker->failure_count = 2;
        $circuitBreaker->save();
        
        $circuitBreaker->recordSuccess();
        
        $this->assertEquals(0, $circuitBreaker->failure_count);
    }

    /** @test */
    public function it_creates_unique_circuit_breakers_per_tenant_and_service()
    {
        $cb1 = CircuitBreaker::forService('api.transactions', 1);
        $cb2 = CircuitBreaker::forService('api.transactions', 2);
        $cb3 = CircuitBreaker::forService('api.payments', 1);
        
        $this->assertNotEquals($cb1->id, $cb2->id);
        $this->assertNotEquals($cb1->id, $cb3->id);
        $this->assertNotEquals($cb2->id, $cb3->id);
    }
}