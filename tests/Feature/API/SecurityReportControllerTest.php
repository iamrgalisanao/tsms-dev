<?php

namespace Tests\Feature\API;

use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use App\Models\User;
use App\Services\Security\SecurityReportingService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery;
use Tests\TestCase;

class SecurityReportControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /**
     * @var SecurityReportingService|Mockery\MockInterface
     */
    private $mockReportingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock of the reporting service
        $this->mockReportingService = Mockery::mock(SecurityReportingService::class);
        $this->app->instance(SecurityReportingService::class, $this->mockReportingService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test the index endpoint returns a list of reports
     *
     * @return void
     */
    public function testIndexEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Mock the service response
        $mockReports = [
            [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'Test Report 1',
                'status' => 'completed',
                'created_at' => now()->toIso8601String()
            ],
            [
                'id' => 2,
                'tenant_id' => 1,
                'name' => 'Test Report 2',
                'status' => 'generating',
                'created_at' => now()->subDay()->toIso8601String()
            ]
        ];

        $this->mockReportingService->shouldReceive('getReportsList')
            ->once()
            ->with(1, [])
            ->andReturn($mockReports);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->getJson('/api/security/reports');

        // Assert the response
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockReports
            ]);
    }

    /**
     * Test the store endpoint creates a new report
     *
     * @return void
     */
    public function testStoreEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Create a template
        $template = SecurityReportTemplate::factory()->create([
            'tenant_id' => 1
        ]);

        // Mock the service response
        $this->mockReportingService->shouldReceive('generateReport')
            ->once()
            ->andReturn(1);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/security/reports', [
                'name' => 'New Test Report',
                'format' => 'html',
                'template_id' => $template->id,
                'from' => now()->subDays(7)->toDateTimeString(),
                'to' => now()->toDateTimeString()
            ]);

        // Assert the response
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'report_id' => 1
            ]);
    }

    /**
     * Test the show endpoint returns a specific report
     *
     * @return void
     */
    public function testShowEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Mock the service response
        $mockReport = [
            'id' => 1,
            'tenant_id' => 1,
            'name' => 'Test Report',
            'status' => 'completed',
            'results' => ['events_summary' => ['total' => 5]],
            'created_at' => now()->toIso8601String()
        ];

        $this->mockReportingService->shouldReceive('getReport')
            ->once()
            ->with(1, 1)
            ->andReturn($mockReport);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->getJson('/api/security/reports/1');

        // Assert the response
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockReport
            ]);
    }

    /**
     * Test the getTemplates endpoint returns a list of templates
     *
     * @return void
     */
    public function testGetTemplatesEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Mock the service response
        $mockTemplates = [
            [
                'id' => 1,
                'tenant_id' => 1,
                'name' => 'Template 1',
                'type' => 'security_events'
            ],
            [
                'id' => 2,
                'tenant_id' => 1,
                'name' => 'Template 2',
                'type' => 'comprehensive'
            ]
        ];

        $this->mockReportingService->shouldReceive('getReportTemplates')
            ->once()
            ->with(1, [])
            ->andReturn($mockTemplates);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->getJson('/api/security/report-templates');

        // Assert the response
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockTemplates
            ]);
    }

    /**
     * Test the storeTemplate endpoint creates a new template
     *
     * @return void
     */
    public function testStoreTemplateEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Mock the service response
        $this->mockReportingService->shouldReceive('createReportTemplate')
            ->once()
            ->andReturn(1);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/security/report-templates', [
                'name' => 'New Template',
                'type' => 'security_events',
                'description' => 'A template for security events',
                'format' => 'html'
            ]);

        // Assert the response
        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'template_id' => 1
            ]);
    }

    /**
     * Test the getTemplate endpoint returns a specific template
     *
     * @return void
     */
    public function testGetTemplateEndpoint()
    {
        // Create a mock user instead of using the factory
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        // Set the tenant_id property directly
        $user->tenant_id = 1;

        // Mock the service response
        $mockTemplate = [
            'id' => 1,
            'tenant_id' => 1,
            'name' => 'Test Template',
            'type' => 'security_events',
            'filters' => ['event_type' => 'authentication']
        ];

        $this->mockReportingService->shouldReceive('getReportTemplate')
            ->once()
            ->with(1, 1)
            ->andReturn($mockTemplate);

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->getJson('/api/security/report-templates/1');

        // Assert the response
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockTemplate
            ]);
    }

    /**
     * Test the export endpoint returns a downloadable file
     *
     * @return void
     */
    public function testExportEndpoint()
    {
        // Create a mock user
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->tenant_id = 1;

        // Create a test report
        $report = SecurityReport::factory()->completed()->create([
            'tenant_id' => 1,
            'format' => 'pdf'
        ]);

        // Define the expected export path
        $exportPath = 'exports/security/1/security_report_' . $report->id . '_test.pdf';

        // Mock the service response
        $this->mockReportingService->shouldReceive('exportReport')
            ->once()
            ->with($report->id, 'pdf')
            ->andReturn($exportPath);

        // Mock the Storage facade
        \Illuminate\Support\Facades\Storage::shouldReceive('exists')
            ->once()
            ->with($exportPath)
            ->andReturn(true);

        \Illuminate\Support\Facades\Storage::shouldReceive('download')
            ->once()
            ->with($exportPath, Mockery::any(), Mockery::any())
            ->andReturn(response()->make('PDF content', 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="security_report.pdf"'
            ]));

        // Make the request
        $response = $this->actingAs($user, 'api')
            ->getJson('/api/security/reports/' . $report->id . '/export?format=pdf');

        // Assert the response
        $response->assertStatus(200);
    }

    /**
     * Test the export endpoint successfully exports a report
     *
     * @return void
     */
    public function testExportEndpointSuccessfullyExportsReport()
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->tenant_id = 1;

        $mockReport = [
            'id' => 1,
            'tenant_id' => 1,
            'name' => 'Test Report',
            'status' => 'completed',
            'results' => ['events_summary' => ['total' => 5]],
            'created_at' => now()->toIso8601String()
        ];

        $mockExportPath = storage_path('app/exports/report-1.pdf');
        
        // Create a temporary file for testing
        file_put_contents($mockExportPath, 'Test PDF content');

        $this->mockReportingService->shouldReceive('getReport')
            ->once()
            ->with(1, 1)
            ->andReturn($mockReport);

        $this->mockReportingService->shouldReceive('exportReport')
            ->once()
            ->with($mockReport, 'pdf')
            ->andReturn($mockExportPath);

        $response = $this->actingAs($user, 'api')
            ->get('/api/security/reports/1/export?format=pdf');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="report-1.pdf"');

        // Clean up the temporary file
        @unlink($mockExportPath);
    }

    /**
     * Test export endpoint handles missing reports correctly
     *
     * @return void
     */
    public function testExportEndpointWithMissingReport()
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->tenant_id = 1;

        $this->mockReportingService->shouldReceive('getReport')
            ->once()
            ->with(1, 1)
            ->andReturn(null);

        $response = $this->actingAs($user, 'api')
            ->get('/api/security/reports/1/export?format=pdf');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Report not found'
            ]);
    }

    /**
     * Test export endpoint handles export failures correctly
     *
     * @return void
     */
    public function testExportEndpointWithExportFailure()
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->tenant_id = 1;

        $mockReport = [
            'id' => 1,
            'tenant_id' => 1,
            'name' => 'Test Report',
            'status' => 'completed',
            'results' => ['events_summary' => ['total' => 5]],
            'created_at' => now()->toIso8601String()
        ];

        $this->mockReportingService->shouldReceive('getReport')
            ->once()
            ->with(1, 1)
            ->andReturn($mockReport);

        $this->mockReportingService->shouldReceive('exportReport')
            ->once()
            ->with($mockReport, 'pdf')
            ->andReturn(null);

        $response = $this->actingAs($user, 'api')
            ->get('/api/security/reports/1/export?format=pdf');

        $response->assertStatus(500)
            ->assertJson([
                'status' => 'error',
                'message' => 'Failed to generate export file'
            ]);
    }
}