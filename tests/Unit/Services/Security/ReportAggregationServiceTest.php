<?php

namespace Tests\Unit\Services\Security;

use App\Models\SecurityReportTemplate;
use App\Services\Security\ReportAggregationService;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class ReportAggregationServiceTest extends TestCase
{
    use RefreshDatabase;    /**
     * Test that the aggregation service can aggregate data for a security events template
     *
     * @return void
     */
    public function testAggregateDataForSecurityEvents()
    {
        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Security Events Report',
            'type' => 'security_events',
            'filters' => [
                'event_type' => 'authentication',
                'severity' => 'critical'
            ]
        ]);

        // Set up the test data in the database
        // Create test security events that should be included in the results
        \App\Models\SecurityEvent::factory()->count(5)->create([
            'tenant_id' => 1,
            'event_type' => 'authentication',
            'severity' => 'critical',
            'event_timestamp' => Carbon::now()->subDays(3)
        ]);
        
        // Create some events that shouldn't be included in the results
        \App\Models\SecurityEvent::factory()->count(3)->create([
            'tenant_id' => 1,
            'event_type' => 'data_access', // different event type
            'severity' => 'critical',
            'event_timestamp' => Carbon::now()->subDays(3)
        ]);
        
        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('metadata', $result);
        
        // Assert the content is filtered correctly - assuming the implementation returns a total count
        // Note: The exact key structure might need adjustment based on actual implementation
        $eventsCount = $result['summary']['total_events'] ?? ($result['summary']['total'] ?? 0);
        $this->assertEquals(5, $eventsCount);
    }

    /**
     * Test that the aggregation service can aggregate data for a failed transactions template
     *
     * @return void
     */
    public function testAggregateDataForFailedTransactions()
    {
        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Failed Transactions Report',
            'type' => 'failed_transactions',
            'filters' => [
                'status' => 'failed'
            ]
        ]);

        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('transactions_summary', $result);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * Test that the aggregation service can aggregate data for a circuit breaker trips template
     *
     * @return void
     */
    public function testAggregateDataForCircuitBreakerTrips()
    {
        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Circuit Breaker Trips Report',
            'type' => 'circuit_breaker_trips'
        ]);

        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('circuit_breaker_summary', $result);
        $this->assertArrayHasKey('generated_at', $result);
    }

    /**
     * Test that the aggregation service can aggregate data for login attempts
     *
     * @return void
     */
    public function testAggregateDataForLoginAttempts()
    {
        // Create a template for login attempts reporting
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Login Attempts Report',
            'type' => 'login_attempts',
            'filters' => [
                'status' => 'failed'
            ]
        ]);

        // Create test login attempt events that should be included
        \App\Models\SecurityEvent::factory()->count(4)->create([
            'tenant_id' => 1,
            'event_type' => 'authentication',
            'action' => 'login',
            'status' => 'failed',
            'event_timestamp' => Carbon::now()->subDays(1)
        ]);
        
        // Create events that shouldn't be included
        \App\Models\SecurityEvent::factory()->count(2)->create([
            'tenant_id' => 1,
            'event_type' => 'authentication',
            'action' => 'login',
            'status' => 'success',
            'event_timestamp' => Carbon::now()->subDays(1)
        ]);
        
        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('metadata', $result);        //Assert the content is filtered correctly
        $failedLoginCount = $result['summary']['failed_attempts'] ?? ($result['summary']['total_failed'] ?? 0);
        $this->assertEquals(4, $failedLoginCount);
    }

    /**
     * Test that the aggregation service can aggregate data for security alerts
     *
     * @return void
     */
    public function testAggregateDataForSecurityAlerts()
    {
        // Create a template for security alerts
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Security Alerts Report',
            'type' => 'security_alerts',
            'filters' => [
                'severity' => 'critical'
            ]
        ]);

        // Create test security alerts that should be included
        \App\Models\SecurityAlert::factory()->count(3)->create([
            'tenant_id' => 1,
            'severity' => 'critical',
            'created_at' => Carbon::now()->subHours(12)
        ]);
        
        // Create alerts that shouldn't be included
        \App\Models\SecurityAlert::factory()->count(2)->create([
            'tenant_id' => 1,
            'severity' => 'warning',
            'created_at' => Carbon::now()->subHours(12)
        ]);
        
        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('metadata', $result);        //Assert the content is filtered correctly
        $criticalAlertsCount = $result['summary']['critical_alerts'] ?? ($result['summary']['total_critical'] ?? 0);
        $this->assertEquals(3, $criticalAlertsCount);
    }

    /**
     * Test that the aggregation service can aggregate data for a comprehensive template
     *
     * @return void
     */
    public function testAggregateDataForComprehensiveReport()
    {
        // Create a template for comprehensive reporting
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Comprehensive Security Report',
            'type' => 'comprehensive',
            'filters' => [
                'events' => [
                    'event_type' => 'authentication',
                    'severity' => 'critical'
                ],
                'alerts' => [
                    'severity' => 'critical'
                ],
                'login_attempts' => [
                    'status' => 'failed'
                ]
            ]
        ]);

        // Create test security events
        \App\Models\SecurityEvent::factory()->count(3)->create([
            'tenant_id' => 1,
            'event_type' => 'authentication',
            'severity' => 'critical',
            'event_timestamp' => Carbon::now()->subDays(2)
        ]);
        
        // Create test security alerts
        \App\Models\SecurityAlert::factory()->count(2)->create([
            'tenant_id' => 1,
            'severity' => 'critical',
            'created_at' => Carbon::now()->subHours(12)
        ]);
        
        // Create test login attempts
        \App\Models\SecurityEvent::factory()->count(4)->create([
            'tenant_id' => 1,
            'event_type' => 'authentication',
            'action' => 'login',
            'status' => 'failed',
            'event_timestamp' => Carbon::now()->subDays(1)
        ]);
        
        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the response structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertArrayHasKey('insights', $result);
        
        // Check that the details sections exist
        $this->assertArrayHasKey('security_events', $result['details']);
        $this->assertArrayHasKey('security_alerts', $result['details']);
        $this->assertArrayHasKey('login_attempts', $result['details']);
    }

    /**
     * Test that insights are generated from the aggregated data
     *
     * @return void
     */
    public function testInsightsGeneration()
    {
        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Comprehensive Security Report',
            'type' => 'comprehensive'
        ]);

        // Create the service
        $service = new ReportAggregationService();
        
        // Call the method
        $result = $service->aggregateData($template, [
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDays(7),
            'end_date' => Carbon::now()
        ]);
        
        // Assert the insights section exists and has the expected structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertIsArray($result['insights']);
        
        // Insights should have specific sections
        $this->assertArrayHasKey('anomalies', $result['insights']);
        $this->assertArrayHasKey('trends', $result['insights']);
        $this->assertArrayHasKey('recommendations', $result['insights']);
    }
}
