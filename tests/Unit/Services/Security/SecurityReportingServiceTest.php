<?php

namespace Tests\Unit\Services\Security;

use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use App\Services\Security\ReportAggregationService;
use App\Services\Security\SecurityReportingService;
use App\Services\Security\SecurityReportExporter;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class SecurityReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var SecurityReportingService
     */
    private $service;

    /**
     * @var ReportAggregationService|Mockery\MockInterface
     */
    private $mockAggregationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAggregationService = Mockery::mock(ReportAggregationService::class);
        $this->service = new SecurityReportingService($this->mockAggregationService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that generateReport uses the aggregation service when a template is provided
     *
     * @return void
     */
    public function testGenerateReportWithTemplate()
    {
        // Create a template and a user
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Test Template',
            'type' => 'security_events'
        ]);

        // Mock the expected return data from the aggregation service
        $mockReportData = [
            'events_summary' => ['total' => 5],
            'generated_at' => Carbon::now()->toIso8601String()
        ];

        // Set expectations on the mock
        $this->mockAggregationService->shouldReceive('aggregateData')
            ->once()
            ->with($template, Mockery::on(function ($params) {
                return $params['tenant_id'] === 1 &&
                    $params['start_date'] instanceof Carbon &&
                    $params['end_date'] instanceof Carbon;
            }))
            ->andReturn($mockReportData);

        // Call the method with a template
        $reportId = $this->service->generateReport(
            1, // tenant_id
            [
                'name' => 'Test Report',
                'from' => Carbon::now()->subDays(7),
                'to' => Carbon::now()
            ],
            'html',
            $template->id,
            1 // user_id
        );

        // Assert a report was created
        $this->assertDatabaseHas('security_reports', [
            'id' => $reportId,
            'tenant_id' => 1,
            'name' => 'Test Report',
            'security_report_template_id' => $template->id,
            'status' => 'completed'
        ]);

        // Get the report from DB and check its data
        $report = SecurityReport::find($reportId);
        $this->assertEquals($mockReportData, $report->results);
    }

    /**
     * Test that generateReport uses legacy approach when no template is provided
     *
     * @return void
     */
    public function testGenerateReportWithoutTemplate()
    {
        // The aggregation service should not be called
        $this->mockAggregationService->shouldNotReceive('aggregateData');

        // Call the method without a template
        $reportId = $this->service->generateReport(
            1, // tenant_id
            [
                'name' => 'Legacy Report',
                'from' => Carbon::now()->subDays(7),
                'to' => Carbon::now()
            ],
            'html'
        );

        // Assert a report was created
        $this->assertDatabaseHas('security_reports', [
            'id' => $reportId,
            'tenant_id' => 1,
            'name' => 'Legacy Report',
            'security_report_template_id' => null,
            'status' => 'completed'
        ]);

        // Get the report and verify it has data from the legacy method
        $report = SecurityReport::find($reportId);
        $this->assertIsArray($report->results);
        $this->assertArrayHasKey('total_events', $report->results);
    }

    /**
     * Test error handling in the generateReport method
     *
     * @return void
     */
    public function testGenerateReportHandlesErrors()
    {
        // Make the aggregation service throw an exception
        $this->mockAggregationService->shouldReceive('aggregateData')
            ->once()
            ->andThrow(new \Exception('Test error'));

        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1,
            'name' => 'Test Template',
            'type' => 'security_events'
        ]);

        // Call the method and expect an exception
        $reportId = $this->service->generateReport(
            1, // tenant_id
            [
                'name' => 'Failed Report',
                'from' => Carbon::now()->subDays(7),
                'to' => Carbon::now()
            ],
            'html',
            $template->id,
            1 // user_id
        );

        // Assert that a failed report was created
        $this->assertDatabaseHas('security_reports', [
            'id' => $reportId,
            'tenant_id' => 1,
            'name' => 'Failed Report',
            'status' => 'failed'
        ]);

        // Verify that the error message was stored
        $report = SecurityReport::find($reportId);
        $this->assertEquals('Test error', $report->error_message);
    }

    /**
     * Test the getReportsList method
     *
     * @return void
     */
    public function testGetReportsList()
    {
        // Create some reports
        SecurityReport::factory()->count(3)->create([
            'tenant_id' => 1,
            'status' => 'completed'
        ]);
        
        SecurityReport::factory()->count(2)->create([
            'tenant_id' => 1,
            'status' => 'failed'
        ]);
        
        // Create reports for a different tenant (should not be returned)
        SecurityReport::factory()->count(2)->create([
            'tenant_id' => 2,
            'status' => 'completed'
        ]);

        // Call the method
        $reports = $this->service->getReportsList(1, ['status' => 'completed']);

        // Assert we get the right number of reports
        $this->assertCount(3, $reports);
        $this->assertEquals('completed', $reports[0]['status']);
    }

    /**
     * Test that the export functionality works
     *
     * @return void
     */
    public function testExportReport()
    {
        // Mock the dependencies
        $mockExporter = Mockery::mock(SecurityReportExporter::class);
        $this->app->instance(SecurityReportExporter::class, $mockExporter);
        
        // Create a test report
        $report = SecurityReport::factory()->completed()->create([
            'tenant_id' => 1,
            'format' => 'pdf'
        ]);
        
        // Set up expectations
        $mockExporter->shouldReceive('exportReport')
            ->once()
            ->with($report->id, 'pdf')
            ->andReturn('exports/security/1/security_report_' . $report->id . '_test.pdf');
            
        // Call the export method with our test report
        $result = $this->service->exportReport($report->id, 'pdf');
        
        // Verify the result
        $this->assertNotNull($result);
        $this->assertStringContainsString('exports/security/1/security_report_', $result);
    }
}
