<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Models\SecurityReport;
use App\Models\SecurityEvent;
use App\Models\SecurityAlert;
use App\Models\SecurityAlertRule;
use App\Services\Security\SecurityReportExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class SecurityReportExporterTest extends TestCase
{
    use RefreshDatabase;

    private SecurityReportExporter $exporter;
    private SecurityReport $report;
    private const TEST_REPORT_NAME = 'Test Security Report';
    private const EXPORT_DIR = 'exports/security';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the export service
        $this->exporter = new SecurityReportExporter();
        
        // Create test data
        $rule = SecurityAlertRule::factory()->create([
            'name' => 'Test Rule'
        ]);

        $this->report = SecurityReport::factory()->create([
            'name' => self::TEST_REPORT_NAME,
            'description' => 'Test Description',
            'from_date' => now()->subDays(7),
            'to_date' => now(),
            'status' => 'completed'
        ]);

        // Create some test events
        SecurityEvent::factory()->count(3)->create([
            'report_id' => $this->report->id,
            'type' => 'login_attempt',
            'severity' => 'warning',
            'description' => 'Failed login attempt',
            'source_ip' => '192.168.1.1'
        ]);

        // Create some test alerts
        SecurityAlert::factory()->count(2)->create([
            'report_id' => $this->report->id,
            'rule_id' => $rule->id,
            'severity' => 'critical',
            'status' => 'active',
            'actions_taken' => 'IP blocked'
        ]);

        // Ensure the export directory exists and is empty
        Storage::deleteDirectory(self::EXPORT_DIR);
        Storage::makeDirectory(self::EXPORT_DIR);
    }

    /** @test */
    public function it_can_export_report_as_csv()
    {
        $csvPath = $this->exporter->exportToCSV($this->report);
        
        $this->assertNotNull($csvPath);
        $this->assertTrue(file_exists($csvPath));
        $csvContent = file_get_contents($csvPath);
        
        $this->assertStringContainsString(self::TEST_REPORT_NAME, $csvContent);
        $this->assertStringContainsString('login_attempt', $csvContent);
        $this->assertStringContainsString('192.168.1.1', $csvContent);
    }

    /** @test */
    public function it_can_export_report_as_pdf()
    {
        $pdfPath = $this->exporter->exportToPDF($this->report);
        
        $this->assertNotNull($pdfPath);
        $this->assertTrue(file_exists($pdfPath));
        $pdfContent = file_get_contents($pdfPath);
        
        // Check if the content is a valid PDF (starts with %PDF header)
        $this->assertStringStartsWith('%PDF', $pdfContent);
    }

    /** @test */
    public function it_can_export_report_as_html()
    {
        $htmlPath = $this->exporter->exportToHTML($this->report);
        
        $this->assertNotNull($htmlPath);
        $this->assertTrue(file_exists($htmlPath));
        $htmlContent = file_get_contents($htmlPath);
        
        $this->assertStringContainsString('<!DOCTYPE html>', $htmlContent);
        $this->assertStringContainsString(self::TEST_REPORT_NAME, $htmlContent);
    }

    /** @test */
    public function it_returns_null_for_invalid_export_format()
    {
        $path = $this->exporter->exportReport($this->report->id, 'invalid');
        $this->assertNull($path);
    }

    /** @test */
    public function it_can_handle_reports_with_special_characters()
    {
        $reportWithSpecialChars = SecurityReport::factory()->create([
            'name' => 'Report with "quotes" & special chars',
            'description' => 'Test description with "quotes" & special characters',
            'tenant_id' => 1,
            'status' => 'completed'
        ]);

        $csvPath = $this->exporter->exportToCSV($reportWithSpecialChars);
        
        $this->assertNotNull($csvPath);
        $this->assertTrue(file_exists($csvPath));
        $csvContent = file_get_contents($csvPath);
        
        $this->assertStringContainsString('Report with "quotes" & special chars', $csvContent);
    }

    /** @test */
    public function it_generates_unique_filenames_for_same_report()
    {
        $path1 = $this->exporter->exportToPDF($this->report);
        sleep(1); // Ensure different timestamp
        $path2 = $this->exporter->exportToPDF($this->report);

        $this->assertNotNull($path1);
        $this->assertNotNull($path2);
        $this->assertNotEquals($path1, $path2);
    }

    protected function tearDown(): void
    {
        // Clean up storage
        Storage::deleteDirectory(self::EXPORT_DIR);
        parent::tearDown();
    }
}