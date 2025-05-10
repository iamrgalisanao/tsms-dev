<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Models\SecurityEvent;
use App\Models\SecurityAlertRule;
use App\Models\Tenant;
use App\Services\Security\SecurityMonitorService;
use App\Services\Security\SecurityAlertHandlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Tests\Traits\NoAuthTestHelpers;

class SecurityMonitorServiceTest extends TestCase
{
    use RefreshDatabase, NoAuthTestHelpers;

    protected SecurityMonitorService $securityMonitor;
    protected SecurityAlertHandlerService $alertHandler;
    protected Tenant $tenant;
    protected $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear the cache
        Cache::flush();
        
        // Create test database
        $this->setUpTestDatabase();
        
        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'code' => 'TEST'
        ]);
        $this->tenantId = $this->tenant->id;
        
        // Create test alert rule
        SecurityAlertRule::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Rule',
            'event_type' => 'suspicious_activity',
            'threshold' => 3,
            'window_minutes' => 5,
            'action' => 'notify',
            'notification_channels' => ['email'], 
            'is_active' => true
        ]);
        
        // Set up services
        $this->alertHandler = new SecurityAlertHandlerService();
        $this->securityMonitor = new SecurityMonitorService($this->alertHandler);
    }
    
    /** @test */
    public function it_can_record_security_event()
    {
        // Act
        $this->securityMonitor->recordEvent(
            'suspicious_activity',
            'warning',
            ['tenant_id' => $this->tenantId, 'test_data' => 'test_value'],
            '127.0.0.1'
        );
        
        // Assert
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'suspicious_activity',
            'severity' => 'warning',
            'tenant_id' => $this->tenantId,
            'source_ip' => '127.0.0.1'
        ]);
    }
    
    /** @test */
    public function it_triggers_alert_when_threshold_reached()
    {
        // Act - Record events up to threshold
        for ($i = 0; $i < 3; $i++) {
            $this->securityMonitor->recordEvent(
                'suspicious_activity',
                'warning',
                ['tenant_id' => $this->tenantId, 'iteration' => $i],
                '127.0.0.1'
            );
        }
        
        // Assert
        $events = SecurityEvent::where('event_type', 'suspicious_activity')
                               ->where('tenant_id', $this->tenantId)
                               ->get();
        $this->assertCount(3, $events);
        
        // Verify alert was triggered
        $this->assertTrue(
            $this->securityMonitor->checkAlertRules($this->tenantId, 'suspicious_activity')
        );
    }
    
    /** @test */
    public function it_respects_event_window()
    {
        // Record 2 events
        for ($i = 0; $i < 2; $i++) {
            $this->securityMonitor->recordEvent(
                'suspicious_activity',
                'warning',
                ['tenant_id' => $this->tenantId, 'iteration' => $i],
                '127.0.0.1'
            );
        }
        
        // Simulate time passing beyond window
        Carbon::setTestNow(now()->addMinutes(6));
        
        // Record one more event
        $this->securityMonitor->recordEvent(
            'suspicious_activity',
            'warning',
            ['tenant_id' => $this->tenantId, 'final' => true],
            '127.0.0.1'
        );
        
        // Assert - Should not trigger alert as window was reset
        $this->assertFalse(
            $this->securityMonitor->checkAlertRules($this->tenantId, 'suspicious_activity')
        );
        
        // Reset the time
        Carbon::setTestNow();
    }
}