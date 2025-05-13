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
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    
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
            'name' => 'Test Security Report',
            'description' => 'Description of test report',
            'status' => 'completed',
            'generated_by' => $user->id,
            'tenant_id' => $tenant->id,
            'from_date' => now()->subDays(7)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'format' => 'html',
            'results' => json_encode([
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
                        'timestamp' => now()->subHours(5)->format(self::DATETIME_FORMAT),
                        'event_type' => 'Login Failure',
                        'severity' => 'Medium',
                        'user' => 'testuser',
                        'ip_address' => '192.168.1.100',
                        'description' => 'Multiple failed login attempts'
                    ]
                ],
                'alerts' => [
                    [
                        'created_at' => now()->subHours(4)->format(self::DATETIME_FORMAT),
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
        $this->assertStringContainsString($this->report->name, $result['filename']);
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
        $this->assertStringContainsString($this->report->name, $result['filename']);
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

    /** @test */
    public function it_can_export_report_with_empty_events_and_alerts()
    {
        // Create a report with no events or alerts
        $emptyReport = SecurityReport::factory()->create([
            'name' => 'Empty Report',
            'description' => 'Report with no events',
            'status' => 'completed',
            'generated_by' => $this->report->generated_by,
            'tenant_id' => $this->report->tenant_id,
            'from_date' => now()->subDays(7)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'format' => 'html',
            'results' => json_encode([
                'total_events' => 0,
                'total_alerts' => 0,
                'severity_breakdown' => [],
                'event_types' => [],
                'events' => [],
                'alerts' => []
            ])
        ]);

        // Test CSV export
        $csvResult = $this->exportService->exportReport($emptyReport, 'csv');
        $this->assertNotNull($csvResult);
        $this->assertStringContainsString('Total Events,0', $csvResult['content']);

        // Test PDF export
        $pdfResult = $this->exportService->exportReport($emptyReport, 'pdf');
        $this->assertNotNull($pdfResult);
        $this->assertStringStartsWith('%PDF', $pdfResult['content']);
    }

    /** @test */
    public function it_handles_special_characters_in_report_data()
    {
        $reportWithSpecialChars = SecurityReport::factory()->create([
            'name' => 'Special Characters Report ñ áéíóú',
            'description' => 'Report with special chars: @#$%^&*()',
            'status' => 'completed',
            'generated_by' => $this->report->generated_by,
            'tenant_id' => $this->report->tenant_id,
            'from_date' => now()->subDays(7)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'format' => 'html',
            'results' => json_encode([
                'total_events' => 1,
                'total_alerts' => 1,
                'severity_breakdown' => ['High' => 1],
                'event_types' => ['Special Event' => 1],
                'events' => [[
                    'timestamp' => now()->format(self::DATETIME_FORMAT),
                    'event_type' => 'Test Event ñ',
                    'severity' => 'High',
                    'user' => 'user@test.com',
                    'ip_address' => '192.168.1.1',
                    'description' => 'Test description with áéíóú'
                ]],
                'alerts' => [[
                    'created_at' => now()->format(self::DATETIME_FORMAT),
                    'title' => 'Alert with ñ',
                    'severity' => 'High',
                    'status' => 'Open',
                    'description' => 'Description with @#$%^&*()'
                ]]
            ])
        ]);

        // Test CSV export with special characters
        $csvResult = $this->exportService->exportReport($reportWithSpecialChars, 'csv');
        $this->assertNotNull($csvResult);
        $this->assertStringContainsString('áéíóú', $csvResult['content']);

        // Test PDF export with special characters
        $pdfResult = $this->exportService->exportReport($reportWithSpecialChars, 'pdf');
        $this->assertNotNull($pdfResult);
        $this->assertStringStartsWith('%PDF', $pdfResult['content']);
    }

    /** @test */
    public function it_can_handle_large_datasets()
    {
        // Create a large number of events and alerts
        $events = [];
        $alerts = [];
        
        for ($i = 0; $i < 1000; $i++) {
            $events[] = [
                'timestamp' => now()->subHours($i)->format(self::DATETIME_FORMAT),
                'event_type' => 'Event ' . $i,
                'severity' => ['Low', 'Medium', 'High', 'Critical'][rand(0, 3)],
                'user' => 'user' . $i . '@test.com',
                'ip_address' => '192.168.1.' . rand(1, 255),
                'description' => 'Test event description ' . $i
            ];
        }

        for ($i = 0; $i < 100; $i++) {
            $alerts[] = [
                'created_at' => now()->subHours($i)->format(self::DATETIME_FORMAT),
                'title' => 'Alert ' . $i,
                'severity' => ['Low', 'Medium', 'High', 'Critical'][rand(0, 3)],
                'status' => ['Open', 'Closed', 'In Progress'][rand(0, 2)],
                'description' => 'Test alert description ' . $i
            ];
        }

        $largeReport = SecurityReport::factory()->create([
            'name' => 'Large Dataset Report',
            'description' => 'Report with large number of events and alerts',
            'status' => 'completed',
            'generated_by' => $this->report->generated_by,
            'tenant_id' => $this->report->tenant_id,
            'from_date' => now()->subDays(7)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'format' => 'html',
            'results' => json_encode([
                'total_events' => count($events),
                'total_alerts' => count($alerts),
                'severity_breakdown' => [
                    'Critical' => 250,
                    'High' => 250,
                    'Medium' => 250,
                    'Low' => 250
                ],
                'event_types' => array_fill_keys(array_map(fn($i) => "Event $i", range(1, 10)), 100),
                'events' => $events,
                'alerts' => $alerts
            ])
        ]);

        // Test CSV export with large dataset
        $csvResult = $this->exportService->exportReport($largeReport, 'csv');
        $this->assertNotNull($csvResult);
        $this->assertStringContainsString('Total Events,1000', $csvResult['content']);

        // Test PDF export with large dataset
        $pdfResult = $this->exportService->exportReport($largeReport, 'pdf');
        $this->assertNotNull($pdfResult);
        $this->assertStringStartsWith('%PDF', $pdfResult['content']);
    }

    /** @test */
    public function it_generates_correct_file_names()
    {
        // Test CSV filename format
        $csvResult = $this->exportService->exportReport($this->report, 'csv');
        $this->assertMatchesRegularExpression(
            '/^security_report_[0-9]{8}_[0-9]{6}\.csv$/',
            basename($csvResult['filename'])
        );

        // Test PDF filename format
        $pdfResult = $this->exportService->exportReport($this->report, 'pdf');
        $this->assertMatchesRegularExpression(
            '/^security_report_[0-9]{8}_[0-9]{6}\.pdf$/',
            basename($pdfResult['filename'])
        );

        // Test filename sanitization
        $reportWithSpecialChars = SecurityReport::factory()->create([
            'name' => 'Test Report: Special & Characters / *',
            'description' => 'Test description',
            'status' => 'completed',
            'generated_by' => $this->report->generated_by,
            'tenant_id' => $this->report->tenant_id,
            'from_date' => now()->subDays(7)->format('Y-m-d'),
            'to_date' => now()->format('Y-m-d'),
            'format' => 'html',
            'results' => $this->report->results
        ]);

        $result = $this->exportService->exportReport($reportWithSpecialChars, 'pdf');
        $this->assertNotNull($result);
        $this->assertDoesNotMatchRegularExpression('/[&\/:*]/', basename($result['filename']));
    }

    /** @test */
    public function it_handles_different_date_formats()
    {
        $reportWithDates = SecurityReport::factory()->create([
            'name' => 'Date Format Test Report',
            'description' => 'Testing different date formats',
            'status' => 'completed',
            'generated_by' => $this->report->generated_by,
            'tenant_id' => $this->report->tenant_id,
            'from_date' => '2023-01-01',
            'to_date' => '2023-12-31',
            'format' => 'html',
            'results' => json_encode([
                'total_events' => 3,
                'total_alerts' => 3,
                'severity_breakdown' => ['Medium' => 3],
                'event_types' => ['Test' => 3],
                'events' => [
                    [
                        'timestamp' => '2023-01-01 00:00:00',
                        'event_type' => 'ISO Format',
                        'severity' => 'Medium',
                        'description' => 'ISO date format test'
                    ],
                    [
                        'timestamp' => '01/15/2023 14:30:00',
                        'event_type' => 'US Format',
                        'severity' => 'Medium',
                        'description' => 'US date format test'
                    ],
                    [
                        'timestamp' => '31-12-2023 23:59:59',
                        'event_type' => 'EU Format',
                        'severity' => 'Medium',
                        'description' => 'EU date format test'
                    ]
                ],
                'alerts' => [
                    [
                        'created_at' => '2023-01-01T00:00:00.000Z',
                        'title' => 'ISO DateTime',
                        'severity' => 'Medium',
                        'status' => 'Open'
                    ],
                    [
                        'created_at' => '2023-06-15 14:30:00',
                        'title' => 'Simple DateTime',
                        'severity' => 'Medium',
                        'status' => 'Open'
                    ],
                    [
                        'created_at' => '2023-12-31 23:59:59',
                        'title' => 'Year End',
                        'severity' => 'Medium',
                        'status' => 'Open'
                    ]
                ]
            ])
        ]);

        // Test CSV export with different date formats
        $csvResult = $this->exportService->exportReport($reportWithDates, 'csv');
        $this->assertNotNull($csvResult);
        $this->assertStringContainsString('2023-01-01', $csvResult['content']);
        $this->assertStringContainsString('2023-12-31', $csvResult['content']);

        // Test PDF export with different date formats
        $pdfResult = $this->exportService->exportReport($reportWithDates, 'pdf');
        $this->assertNotNull($pdfResult);
        $this->assertStringStartsWith('%PDF', $pdfResult['content']);
    }
}