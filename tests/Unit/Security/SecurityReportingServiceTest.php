<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\Tenant;
use App\Services\Security\SecurityReportingService;
use App\Services\Security\ReportAggregationService;
use App\Services\Security\SecurityReportExporter;
use App\Exceptions\SecurityReportExportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Traits\NoAuthTestHelpers;
use Mockery;
use Mockery\MockInterface;
use Mockery\LegacyMockInterface;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class SecurityReportingServiceTest extends TestCase
{
    use RefreshDatabase, NoAuthTestHelpers, MockeryPHPUnitIntegration;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const TEST_SOURCE_IP = '192.168.1.1';
    private const TEST_REPORT_NAME = 'Test Report';

    /** @var ReportAggregationService|MockInterface|LegacyMockInterface */
    protected $aggregationService;

    /** @var SecurityReportExporter|MockInterface|LegacyMockInterface */
    protected $reportExporter;

    protected SecurityReportingService $reportingService;
    protected Tenant $tenant;
    protected User $user;
    protected SecurityReportTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test database
        $this->setUpTestDatabase();

        // Create test tenant
        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'status' => 'active'
        ]);

        // Create test user
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'test@example.com'
        ]);

        // Create test template
        $this->template = SecurityReportTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Template',
            'description' => 'Test Template Description',
            'type' => 'event_summary',
            'format' => 'html',
            'filters' => ['severity' => 'high'],
            'is_scheduled' => false
        ]);

        // Set up service with mocks
        $this->aggregationService = Mockery::mock(ReportAggregationService::class);
        $this->reportExporter = Mockery::mock(SecurityReportExporter::class);
        $this->reportingService = new SecurityReportingService(
            $this->aggregationService,
            $this->reportExporter
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_generates_legacy_report_data_with_all_filters()
    {
        // Arrange
        $timestamp = now();
        $event = SecurityEvent::factory()->create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'login_failure',
            'severity' => 'high',
            'event_timestamp' => $timestamp,
            'source_ip' => self::TEST_SOURCE_IP,
            'user_id' => $this->user->id,
            'context' => ['reason' => 'invalid_password']
        ]);

        $filters = [
            'event_type' => 'login_failure',
            'severity' => 'high',
            'from' => $timestamp->copy()->subMinute()->format(self::DATETIME_FORMAT),
            'to' => $timestamp->copy()->addMinute()->format(self::DATETIME_FORMAT),
            'source_ip' => self::TEST_SOURCE_IP,
            'user_id' => $this->user->id
        ];

        // Act
        $reportId = $this->reportingService->generateReport(
            $this->tenant->id,
            $filters,
            'html',
            null,
            $this->user->id
        );

        // Assert
        $report = SecurityReport::find($reportId);
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);

        $results = is_array($report->results) ? $report->results : json_decode($report->results, true);
        $this->assertEquals(1, $results['total_events']);
        $this->assertEquals(1, $results['events_by_type']['login_failure']);
        $this->assertEquals(1, $results['events_by_severity']['high']);

        $eventData = $results['events_list'][0];
        $this->assertEquals($event->id, $eventData['id']);
        $this->assertEquals('login_failure', $eventData['event_type']);
        $this->assertEquals('high', $eventData['severity']);
        $this->assertEquals(self::TEST_SOURCE_IP, $eventData['source_ip']);
        $this->assertEquals($this->user->id, $eventData['user_id']);
    }

    /** @test */
    public function it_respects_event_limit_in_legacy_report()
    {
        // Arrange
        SecurityEvent::factory()->count(1500)->create([
            'tenant_id' => $this->tenant->id,
            'event_type' => 'system_access',
            'severity' => 'medium'
        ]);

        // Act
        $reportId = $this->reportingService->generateReport(
            $this->tenant->id,
            [],
            'html',
            null,
            $this->user->id
        );

        // Assert
        $report = SecurityReport::find($reportId);
        $this->assertNotNull($report);

        $results = is_array($report->results) ? $report->results : json_decode($report->results, true);
        $this->assertCount(1000, $results['events_list']);
        $this->assertEquals(1000, $results['total_events']);
    }

    /** @test */
    public function it_handles_empty_result_set_in_legacy_report()
    {
        // Arrange
        $filters = [
            'event_type' => 'nonexistent_type',
            'severity' => 'critical'
        ];

        // Act
        $reportId = $this->reportingService->generateReport(
            $this->tenant->id,
            $filters,
            'html',
            null,
            $this->user->id
        );

        // Assert
        $report = SecurityReport::find($reportId);
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->status);

        $results = is_array($report->results) ? $report->results : json_decode($report->results, true);
        $this->assertEquals(0, $results['total_events']);
        $this->assertEmpty($results['events_by_type']);
        $this->assertEmpty($results['events_by_severity']);
        $this->assertEmpty($results['events_list']);
        $this->assertEquals($filters, $results['filters']);
    }

    /** @test */
    public function it_can_generate_report()
    {
        // Arrange
        $filters = [
            'name' => self::TEST_REPORT_NAME,
            'from' => now()->subDays(7)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'event_type' => 'login_failure',
            'severity' => 'high'
        ];

        $aggregatedData = [
            'total_events' => 5,
            'total_alerts' => 2,
            'severity_breakdown' => ['high' => 5],
            'event_types' => ['login_failure' => 5],
            'events' => [],
            'alerts' => []
        ];

        $this->aggregationService
            ->shouldReceive('aggregateData')
            ->once()
            ->andReturn($aggregatedData);

        // Act
        $reportId = $this->reportingService->generateReport(
            $this->tenant->id,
            $filters,
            'html',
            $this->template->id,
            $this->user->id
        );

        // Assert
        $this->assertNotNull($reportId);
        $this->assertDatabaseHas('security_reports', [
            'id' => $reportId,
            'tenant_id' => $this->tenant->id,
            'security_report_template_id' => $this->template->id,
            'name' => self::TEST_REPORT_NAME,
            'status' => 'completed',
            'generated_by' => $this->user->id
        ]);
    }

    /** @test */
    public function it_can_get_report()
    {
        // Arrange
        $report = SecurityReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'security_report_template_id' => $this->template->id,
            'name' => self::TEST_REPORT_NAME,
            'status' => 'completed',
            'generated_by' => $this->user->id
        ]);

        // Act
        $result = $this->reportingService->getReport($report->id, $this->tenant->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($report->id, $result['id']);
        $this->assertEquals(self::TEST_REPORT_NAME, $result['name']);
        $this->assertEquals('completed', $result['status']);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_report()
    {
        // Act
        $result = $this->reportingService->getReport(999, $this->tenant->id);

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    public function it_can_get_reports_list()
    {
        // Arrange
        SecurityReport::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'completed'
        ]);

        // Act
        $reports = $this->reportingService->getReportsList($this->tenant->id);

        // Assert
        $this->assertCount(3, $reports);
    }

    /** @test */
    public function it_can_filter_reports_list()
    {
        // Arrange
        SecurityReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'completed',
            'format' => 'pdf'
        ]);
        SecurityReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'failed',
            'format' => 'html'
        ]);

        // Act
        $reports = $this->reportingService->getReportsList($this->tenant->id, [
            'status' => 'completed',
            'format' => 'pdf'
        ]);

        // Assert
        $this->assertCount(1, $reports);
        $this->assertEquals('completed', $reports[0]['status']);
        $this->assertEquals('pdf', $reports[0]['format']);
    }

    /** @test */
    public function it_can_create_report_template()
    {
        // Arrange
        $templateData = [
            'name' => 'New Template',
            'description' => 'New template description',
            'type' => 'event_summary',
            'filters' => ['severity' => 'high'],
            'format' => 'pdf',
            'is_scheduled' => true,
            'schedule_frequency' => 'daily'
        ];

        // Act
        $templateId = $this->reportingService->createReportTemplate($this->tenant->id, $templateData);

        // Assert
        $this->assertNotNull($templateId);
        $this->assertDatabaseHas('security_report_templates', [
            'id' => $templateId,
            'tenant_id' => $this->tenant->id,
            'name' => 'New Template',
            'type' => 'event_summary',
            'format' => 'pdf',
            'is_scheduled' => true,
            'schedule_frequency' => 'daily'
        ]);
    }

    /** @test */
    public function it_can_get_report_template()
    {
        // Act
        $result = $this->reportingService->getReportTemplate($this->template->id, $this->tenant->id);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($this->template->id, $result['id']);
        $this->assertEquals('Test Template', $result['name']);
    }

    /** @test */
    public function it_returns_null_for_nonexistent_template()
    {
        // Act
        $result = $this->reportingService->getReportTemplate(999, $this->tenant->id);

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    public function it_can_get_report_templates()
    {
        // Arrange
        SecurityReportTemplate::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id
        ]);

        // Act
        $templates = $this->reportingService->getReportTemplates($this->tenant->id);

        // Assert
        $this->assertCount(3, $templates); // Including the one created in setUp
    }

    /** @test */
    public function it_can_filter_report_templates()
    {
        // Arrange
        SecurityReportTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'alert_summary',
            'is_scheduled' => true
        ]);

        // Act
        $templates = $this->reportingService->getReportTemplates($this->tenant->id, [
            'type' => 'alert_summary',
            'is_scheduled' => true
        ]);

        // Assert
        $this->assertCount(1, $templates);
        $this->assertEquals('alert_summary', $templates[0]['type']);
        $this->assertTrue($templates[0]['is_scheduled']);
    }

    /** @test */
    public function it_can_export_report()
    {
        // Arrange
        $report = SecurityReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'security_report_template_id' => $this->template->id,
            'name' => self::TEST_REPORT_NAME,
            'status' => 'completed',
            'format' => 'pdf'
        ]);

        $this->reportExporter
            ->shouldReceive('exportReport')
            ->once()
            ->with($report->id, 'pdf')
            ->andReturn('/path/to/report.pdf');

        // Act
        $result = $this->reportingService->exportReport($report, 'pdf');

        // Assert
        $this->assertEquals('/path/to/report.pdf', $result);
    }

    /** @test */
    public function it_throws_exception_for_failed_export()
    {
        // Arrange
        $report = SecurityReport::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => self::TEST_REPORT_NAME,
            'status' => 'completed'
        ]);

        $this->reportExporter
            ->shouldReceive('exportReport')
            ->once()
            ->andThrow(new SecurityReportExportException('Export failed'));

        // Assert & Act
        $this->expectException(SecurityReportExportException::class);
        $this->expectExceptionMessage('Failed to export report: Export failed');

        $this->reportingService->exportReport($report, 'pdf');
    }

    /** @test */
    public function it_handles_generate_report_failure()
    {
        // Arrange
        $filters = [
            'name' => self::TEST_REPORT_NAME,
            'event_type' => 'login_failure'
        ];

        $this->aggregationService
            ->shouldReceive('aggregateData')
            ->once()
            ->andThrow(new \Exception('Aggregation failed'));

        // Act
        $reportId = $this->reportingService->generateReport(
            $this->tenant->id,
            $filters,
            'html',
            $this->template->id,
            $this->user->id
        );

        // Assert
        $this->assertDatabaseHas('security_reports', [
            'id' => $reportId,
            'status' => 'failed',
            'error_message' => 'Aggregation failed'
        ]);
    }
}