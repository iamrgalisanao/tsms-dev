<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Models\SecurityReport;
use App\Models\User;
use App\Models\Tenant;
use App\Services\Security\SecurityReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class SecurityReportExportServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected SecurityReportExportService $exportService;
    protected SecurityReport $report;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the export service
        $this->exportService = new SecurityReportExportService();
        
        // Create a tenant
        $tenant = Tenant::factory()->create([
            'name' => 'Test Tenant'
        ]);
        
        // Create a user
        $user = User::factory()->create([
            'tenant_id' => $tenant->id
        ]);
        
        // Create a test security report
        $this->report = SecurityReport::factory()->create([
            'title' => 'Test Security Report',
            'description' => 'Description of test report',
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'date_range_start' => now()->subDays(7)->format('Y-m-d'),
            'date_range_end' => now()->format('Y-m-d'),
            'report_data' => json_encode([
                'total_events' => 15,
                'total_alerts' => 5,
                'severity_breakdown' => [
                    'Critical' => 1,
                    'High' => 2,
                    'Medium' => 5,
                    'Low' => 7
                ],
                'event_types' => [
                    'Login Failure' => 8,
                    'Access Denied' => 4,
                    'Configuration Change' => 3
                ],
                'events' => [
                    [
                        'timestamp' => now()->subHours(5)->format('Y-m-d H:i:s'),
                        'event_type' => 'Login Failure',
                        'severity' => 'Medium',
                        'user' => 'testuser',
                        'ip_address' => '192.168.1.100',
                        'description' => 'Multiple failed login attempts'
                    ]
                ],
                'alerts' => [
                    [
                        'created_at' => now()->subHours(4)->format('Y-m-d H:i:s'),
                        'title' => 'Suspicious Login Activity',
                        'severity' => 'High',
                        'status' => 'Open',
                        'description' => 'Multiple failed login attempts from same IP'
                    ]
                ]
            ])
        ]);
    }
    
    /** @test */
    public function it_can_export_report_as_csv()
    {
        // Export the report as CSV
        $result = $this->exportService->exportReport($this->report, 'csv');
        
        // Check the export result
        $this->assertNotNull($result);
        $this->assertEquals('text/csv', $result['mime']);
        $this->assertStringContainsString($this->report->title, $result['filename']);
        $this->assertStringEndsWith('.csv', $result['filename']);
        
        // Check CSV content
        $csvContent = $result['content'];
        $this->assertIsString($csvContent);
        $this->assertStringContainsString('Test Security Report', $csvContent);
        $this->assertStringContainsString('Total Events,15', $csvContent);
        $this->assertStringContainsString('Critical,1', $csvContent);
    }
    
    /** @test */
    public function it_can_export_report_as_pdf()
    {
        // Export the report as PDF
        $result = $this->exportService->exportReport($this->report, 'pdf');
        
        // Check the export result
        $this->assertNotNull($result);
        $this->assertEquals('application/pdf', $result['mime']);
        $this->assertStringContainsString($this->report->title, $result['filename']);
        $this->assertStringEndsWith('.pdf', $result['filename']);
        
        // Check if the content is a valid PDF
        // The %PDF header indicates a valid PDF file
        $this->assertStringStartsWith('%PDF', $result['content']);
    }
    
    /** @test */
    public function it_returns_null_for_invalid_export_format()
    {
        // Try to export with an invalid format
        $result = $this->exportService->exportReport($this->report, 'invalid');
        
        // Should return null for invalid format
        $this->assertNull($result);
    }
    
    /** @test */
    public function it_handles_exceptions_gracefully()
    {
        // Create an invalid report (without required data) to trigger an exception
        $invalidReport = new SecurityReport();
        
        // Export should return null when an exception occurs
        $result = $this->exportService->exportReport($invalidReport, 'pdf');
        $this->assertNull($result);
    }
}
