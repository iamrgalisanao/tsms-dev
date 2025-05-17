<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class RetryHistoryTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $adminUser;
    protected $terminal;
    protected $tenant;

    public function setUp(): void
    {
        parent::setUp();
        
        // Create a tenant
        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant'
        ]);
        
        // Create a regular user
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Regular User'
        ]);
        
        // Create admin user with role
        $this->adminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin User',
            'is_admin' => true
        ]);
        
        if (method_exists($this->adminUser, 'assignRole')) {
            $this->adminUser->assignRole('admin');
        }
        
        // Create test terminal
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_uid' => 'TERM-TEST-001'
        ]);
        
        // Create test logs with retry information
        $this->createRetryLogs();
    }
    
    /**
     * Create test logs with retry information
     */
    protected function createRetryLogs()
    {
        // Create some retry logs
        for ($i = 0; $i < 5; $i++) {
            $status = $i % 2 == 0 ? 'SUCCESS' : 'FAILED';
            $retryCount = $i + 1; // At least 1 retry
            
            $log = IntegrationLog::create([
                'tenant_id' => $this->tenant->id,
                'terminal_id' => $this->terminal->id,
                'transaction_id' => 'TX-RETRY-' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'request_payload' => json_encode(['amount' => 100 + $i]),
                'response_payload' => json_encode(['status' => $status]),
                'status' => $status,
                'error_message' => $status == 'FAILED' ? 'Test error requiring retry' : null,
                'http_status_code' => $status == 'SUCCESS' ? 200 : 500,
                'source_ip' => '127.0.0.1',
                'retry_count' => $retryCount,
                'response_time' => 100 + $i * 10,
                'retry_reason' => "Test retry reason {$i}",
                'last_retry_at' => now()->subHours($i),
                'retry_success' => $status == 'SUCCESS',
                'validation_status' => $status == 'SUCCESS' ? 'PASSED' : 'FAILED',
            ]);
        }
    }
    
    /** @test */
    public function user_can_view_retry_history_page()
    {
        $response = $this->actingAs($this->user)
            ->get(route('dashboard.retry-history'));
            
        $response->assertStatus(200);
        $response->assertViewIs('dashboard.retry-history');
        $response->assertSee('Retry History');
    }
    
    /** @test */
    public function it_returns_retry_logs_via_api()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/retry-history');
            
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'analytics' => ['total_retries', 'success_rate', 'avg_response_time']
        ]);
        
        // Check that we have the retry logs
        $retryLogs = IntegrationLog::whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->count();
            
        $this->assertEquals($retryLogs, count($response->json('data')));
    }
    
    /** @test */
    public function it_filters_retry_logs_by_status()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/retry-history?status=SUCCESS');
            
        $response->assertStatus(200);
        
        $successfulRetries = IntegrationLog::whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->where('status', 'SUCCESS')
            ->count();
            
        $this->assertEquals($successfulRetries, count($response->json('data')));
        
        // Each returned log should have SUCCESS status
        foreach ($response->json('data') as $log) {
            $this->assertEquals('SUCCESS', $log['status']);
        }
    }
    
    /** @test */
    public function it_filters_retry_logs_by_terminal()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/retry-history?terminal_id=' . $this->terminal->id);
            
        $response->assertStatus(200);
        
        $terminalRetries = IntegrationLog::whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->where('terminal_id', $this->terminal->id)
            ->count();
            
        $this->assertEquals($terminalRetries, count($response->json('data')));
    }
    
    /** @test */
    public function it_returns_retry_analytics()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/retry-history/analytics');
            
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'total_retries',
            'success_rate',
            'avg_response_time'
        ]);
        
        // Validate analytics data
        $totalRetries = IntegrationLog::whereNotNull('retry_count')->sum('retry_count');
        $this->assertEquals($totalRetries, $response->json('total_retries'));
    }
    
    /** @test */
    public function it_returns_retry_config()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/retry-history/config');
            
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'max_retry_attempts',
            'retry_delay',
            'backoff_multiplier',
            'circuit_breaker_threshold'
        ]);
    }
    
    /** @test */
    public function it_can_trigger_manual_retry()
    {
        // Get a failed log to retry
        $failedLog = IntegrationLog::where('status', 'FAILED')
            ->whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->first();
            
        if (!$failedLog) {
            $this->markTestSkipped('No failed logs with retry count to test manual retry');
        }
        
        $originalRetryCount = $failedLog->retry_count;
        
        $response = $this->actingAs($this->adminUser)
            ->postJson("/api/web/dashboard/retry-history/{$failedLog->id}/retry");
            
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Transaction queued for manual retry',
            'transaction_id' => $failedLog->transaction_id
        ]);
        
        // Refresh the log and check that retry count was incremented
        $failedLog->refresh();
        $this->assertEquals($originalRetryCount + 1, $failedLog->retry_count);
        $this->assertEquals('Manual retry initiated by admin', $failedLog->retry_reason);
    }
}
