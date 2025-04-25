<?php


namespace Tests\Unit;

use Tests\TestCase;
use App\Http\Controllers\API\V1\TransactionController;
use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\TransactionValidationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;
    
    protected $controller;
    protected $validatorMock;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->validatorMock = Mockery::mock(TransactionValidationService::class);
        $this->controller = new TransactionController($this->validatorMock);
    }
    
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
    
    /** @test */
    public function it_configures_network_error_retries_correctly()
    {
        // Arrange
        $terminal = PosTerminal::factory()->create([
            'retry_enabled' => true,
            'max_retries' => 5,
            'retry_interval_sec' => 120
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
            'status' => 'FAILED',
            'retry_count' => null,
            'next_retry_at' => null
        ]);
        
        // Act - Use reflection to access protected method
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'NETWORK_ERROR');
        
        // Assert
        $this->assertEquals(0, $log->retry_count);
        $this->assertEquals('NETWORK_ERROR', $log->retry_reason);
        // Network errors should retry quickly (60 seconds)
        $this->assertTrue($log->next_retry_at->between(
            now()->addSeconds(59), 
            now()->addSeconds(61)
        ));
        
        // Assert retry history was created
        $history = json_decode($log->retry_history, true);
        $this->assertCount(1, $history);
        $this->assertEquals(0, $history[0]['attempt']);
        $this->assertEquals('NETWORK_ERROR', $history[0]['reason']);
    }
    
    /** @test */
    public function it_configures_validation_error_retries_with_longer_delay()
    {
        // Arrange
        $terminal = PosTerminal::factory()->create([
            'retry_enabled' => true,
            'max_retries' => 3
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
            'status' => 'FAILED'
        ]);
        
        // Act
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'VALIDATION_ERROR');
        
        // Assert - Validation errors have a 30-minute delay
        $this->assertEquals(0, $log->retry_count);
        $this->assertEquals('VALIDATION_ERROR', $log->retry_reason);
        $this->assertTrue($log->next_retry_at->between(
            now()->addMinutes(29), 
            now()->addMinutes(31)
        ));
    }
    
    /** @test */
    public function it_uses_exponential_backoff_for_server_errors()
    {
        // Arrange
        $terminal = PosTerminal::factory()->create([
            'retry_enabled' => true,
            'max_retries' => 5,
            'retry_interval_sec' => 300
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
            'status' => 'FAILED',
            'retry_count' => 2  // Third attempt will use 2^2 = 4x backoff
        ]);
        
        // Act
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'SERVER_ERROR');
        
        // Assert - Should use exponential backoff: 300 * 2^2 = 1200 seconds ± jitter
        // With jitter of ±30 seconds, it should be between 1170-1230 seconds
        $this->assertEquals('SERVER_ERROR', $log->retry_reason);
        $this->assertTrue($log->next_retry_at->between(
            now()->addSeconds(270), 
            now()->addSeconds(1230)  // Account for jitter
        ));
    }
    
    /** @test */
    public function it_marks_transaction_as_permanently_failed_when_max_retries_exceeded()
    {
        // Arrange
        $terminal = PosTerminal::factory()->create([
            'retry_enabled' => true,
            'max_retries' => 3
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'tenant_id' => $terminal->tenant_id,
            'status' => 'FAILED',
            'retry_count' => 3  // Already at max retries
        ]);
        
        // Set up expectation for TransactionPermanentlyFailed event
        $this->expectsEvents(\App\Events\TransactionPermanentlyFailed::class);
        
        // Act
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'GENERAL_ERROR');
        
        // Assert
        $this->assertEquals('PERMANENTLY_FAILED', $log->status);
        $this->assertEquals('MAX_RETRIES_EXCEEDED', $log->retry_reason);
        $this->assertNull($log->next_retry_at);
    }
    
    /** @test */
    public function it_respects_tenant_level_max_retries_when_terminal_settings_absent()
    {
        // Arrange
        $tenant = Tenant::factory()->create(['max_retries' => 2]);
        
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'retry_enabled' => true,
            'max_retries' => null  // No terminal-specific setting
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'tenant_id' => $tenant->id,
            'status' => 'FAILED',
            'retry_count' => 2  // At tenant's max retries
        ]);
        
        // Act
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'GENERAL_ERROR');
        
        // Assert - Should be marked as permanently failed because it reached tenant's max_retries
        $this->assertEquals('PERMANENTLY_FAILED', $log->status);
    }
    
    /** @test */
    public function it_doesnt_configure_retries_when_terminal_has_retries_disabled()
    {
        // Arrange
        $terminal = PosTerminal::factory()->create([
            'retry_enabled' => false
        ]);
        
        $log = IntegrationLog::factory()->create([
            'terminal_id' => $terminal->id,
            'status' => 'FAILED',
            'retry_count' => null,
            'next_retry_at' => null
        ]);
        
        // Act
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('configureRetryParams');
        $method->setAccessible(true);
        $method->invoke($this->controller, $log, $terminal, 'NETWORK_ERROR');
        
        // Assert - No changes should be made
        $this->assertNull($log->retry_count);
        $this->assertNull($log->next_retry_at);
    }
}