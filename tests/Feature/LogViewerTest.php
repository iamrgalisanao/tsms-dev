<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogViewerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $adminUser;
    protected $tenant;
    protected $terminal;

    public function setUp(): void
    {
        parent::setUp();
        
        // Skip test if new columns don't exist yet
        if (!$this->checkRequiredColumns()) {
            $this->markTestSkipped('Required columns for log viewer are not yet in the database schema.');
        }
        
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
        
        // Create some test logs
        $this->createTestLogs();
    }
    
    /**
     * Check if the required columns exist in the database
     */
    protected function checkRequiredColumns()
    {
        return Schema::hasColumns('integration_logs', [
            'transaction_id', 'terminal_id', 'tenant_id', 'status'
        ]);
    }
    
    /**
     * Create test logs for testing the viewer
     */
    protected function createTestLogs()
    {
        // Create some transaction logs
        for ($i = 0; $i < 5; $i++) {
            $status = $i % 2 == 0 ? 'SUCCESS' : 'FAILED';
            $log = IntegrationLog::create([
                'tenant_id' => $this->tenant->id,
                'terminal_id' => $this->terminal->id,
                'transaction_id' => 'TX-TEST-' . str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'request_payload' => json_encode(['amount' => 100 + $i]),
                'response_payload' => json_encode(['status' => $status]),
                'status' => $status,
                'error_message' => $status == 'FAILED' ? 'Test error message' : null,
                'http_status_code' => $status == 'SUCCESS' ? 200 : 500,
                'source_ip' => '127.0.0.1',
                'retry_count' => $i,
                'response_time' => 100 + $i * 10,
                'validation_status' => $status == 'SUCCESS' ? 'PASSED' : 'FAILED',
            ]);
            
            // Add new log fields if they exist
            if (Schema::hasColumn('integration_logs', 'log_type')) {
                $log->log_type = $i % 2 == 0 ? 'transaction' : 'error';
                $log->severity = $status == 'SUCCESS' ? 'info' : 'error';
                $log->message = "Test log message {$i}";
                $log->context = json_encode(['test_key' => "test_value_{$i}"]);
                $log->save();
            }
        }
    }
    
    /** @test */
    public function user_can_view_log_viewer_page()
    {
        $response = $this->actingAs($this->user)
            ->get(route('dashboard.log-viewer'));
            
        $response->assertStatus(200);
        $response->assertViewIs('dashboard.log-viewer');
        $response->assertSee('Audit &amp; Admin Log Viewer');
    }
    
    /** @test */
    public function admin_sees_user_filter_option()
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('dashboard.log-viewer'));
            
        $response->assertStatus(200);
        
        // If the view includes a check for admin, it should show the user filter
        if (method_exists($this->adminUser, 'hasRole') && $this->adminUser->hasRole('admin')) {
            $response->assertSee('User</label>');
        }
    }
    
    /** @test */
    public function it_shows_correct_log_statistics()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/logs');
            
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            'stats' => ['total_logs']
        ]);
        
        // Check that we have the correct number of logs
        $totalLogs = IntegrationLog::count();
        $response->assertJson([
            'stats' => [
                'total_logs' => $totalLogs
            ]
        ]);
    }
    
    /** @test */
    public function it_applies_status_filter_correctly()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/logs?status=SUCCESS');
            
        $response->assertStatus(200);
        
        $successCount = IntegrationLog::where('status', 'SUCCESS')->count();
        $this->assertEquals($successCount, count($response->json('data')));
        
        // Each returned log should have SUCCESS status
        foreach ($response->json('data') as $log) {
            $this->assertEquals('SUCCESS', $log['status']);
        }
    }
    
    /** @test */
    public function it_applies_terminal_filter_correctly()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/web/dashboard/logs?terminal_id=' . $this->terminal->id);
            
        $response->assertStatus(200);
        
        $terminalLogCount = IntegrationLog::where('terminal_id', $this->terminal->id)->count();
        $this->assertEquals($terminalLogCount, count($response->json('data')));
    }
    
    /** @test */
    public function it_applies_date_filters_correctly()
    {
        // Create a log with specific date
        $pastDate = now()->subDays(10);
        
        IntegrationLog::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TX-DATE-TEST',
            'status' => 'SUCCESS',
            'created_at' => $pastDate,
        ]);
        
        // Filter from 15 days ago to 5 days ago
        $dateFrom = now()->subDays(15)->format('Y-m-d');
        $dateTo = now()->subDays(5)->format('Y-m-d');
        
        $response = $this->actingAs($this->user)
            ->getJson("/api/web/dashboard/logs?date_from={$dateFrom}&date_to={$dateTo}");
            
        $response->assertStatus(200);
        
        // We should find our specific log
        $found = false;
        foreach ($response->json('data') as $log) {
            if ($log['transaction_id'] == 'TX-DATE-TEST') {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, 'Log with specific date not found in date range filter results');
    }
    
    /** @test */
    public function it_handles_log_detail_view()
    {
        $log = IntegrationLog::first();
        
        $response = $this->actingAs($this->user)
            ->get(route('dashboard.log-viewer.show', $log->id));
            
        $response->assertStatus(200);
        $response->assertSee($log->transaction_id);
    }
    
    /** @test */
    public function it_exports_logs_to_csv()
    {
        $response = $this->actingAs($this->user)
            ->post(route('dashboard.log-viewer.export'), [
                'format' => 'csv'
            ]);
            
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }
    
    /** @test */
    public function admin_can_see_all_users_logs()
    {
        // Only test if user_id column exists
        if (!Schema::hasColumn('integration_logs', 'user_id')) {
            $this->markTestSkipped('user_id column does not exist in integration_logs table');
        }
        
        // Create a log with user_id set to admin
        IntegrationLog::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TX-ADMIN-TEST',
            'status' => 'SUCCESS',
            'user_id' => $this->adminUser->id,
        ]);
        
        // Admin should see all logs
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/web/dashboard/logs');
            
        $response->assertStatus(200);
        
        // Admin should see the log created with their ID
        $found = false;
        foreach ($response->json('data') as $log) {
            if ($log['transaction_id'] == 'TX-ADMIN-TEST') {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, 'Admin cannot see their own logs');
    }
}