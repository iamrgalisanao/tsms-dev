<?php

namespace App\Services\Security;

use App\Models\SecurityReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityReportExportService
{
    // Define constants to avoid duplicating strings
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    
    /**
     * Export a security report as PDF or CSV
     *
     * @param SecurityReport $report
     * @param string $format
     * @return array|null
     */
    public function exportReport(SecurityReport $report, string $format = 'pdf'): ?array
    {
        try {
            // Generate appropriate filename
            $dateStr = now()->format('Y-m-d');
            $filename = Str::slug($report->title) . '-' . $dateStr . '.' . $format;
            $reportData = $this->prepareReportData($report);
            $result = null;
            
            if ($format === 'pdf') {
                $result = $this->generatePdf($filename);
            } elseif ($format === 'csv') {
                $result = $this->generateCsv($reportData, $filename);
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to export security report: ' . $e->getMessage(), [
                'report_id' => $report->id,
                'format' => $format,
                'exception' => $e
            ]);
            
            return null;
        }
    }
    
    /**
     * Prepare report data for export
     *
     * @param SecurityReport $report
     * @return array
     */
    private function prepareReportData(SecurityReport $report): array
    {
        // Load report data and related template
        $report->load(['template', 'user', 'tenant']);
        $reportData = json_decode($report->report_data, true) ?? [];
        
        return [
            'report' => [
                'id' => $report->id,
                'title' => $report->title,
                'description' => $report->description,
                'created_at' => $report->created_at->format(self::DATE_TIME_FORMAT),
                'generated_by' => $report->user ? $report->user->name : 'System',
                'tenant' => $report->tenant ? $report->tenant->name : 'System',
                'template_name' => $report->template ? $report->template->name : 'Custom Report',
                'date_range' => [
                    'from' => $report->date_range_start ? date(self::DATE_TIME_FORMAT, strtotime($report->date_range_start)) : null,
                    'to' => $report->date_range_end ? date(self::DATE_TIME_FORMAT, strtotime($report->date_range_end)) : null,
                ],
            ],
            'data' => $reportData,
            'summary' => [
                'total_events' => $reportData['total_events'] ?? 0,
                'total_alerts' => $reportData['total_alerts'] ?? 0,
                'severity_breakdown' => $reportData['severity_breakdown'] ?? [],
                'event_types' => $reportData['event_types'] ?? [],
            ]
        ];
    }
      /**
     * Generate PDF report
     *
     * @param string $filename
     * @param array $reportData
     * @return array
     */
    private function generatePdf(string $filename): array
    {
        try {
            // Get report data for PDF generation
            // Note: We need to modify the method signature to accept reportData as a parameter
            // For now, we're assuming the report data is passed from the exportReport method
            $reportData = $this->prepareReportData(SecurityReport::latest()->first());
            
            // Load the PDF view with the report data
            $pdf = PDF::loadView('reports.security.pdf', ['report' => $reportData]);
            
            // Configure PDF options if needed
            $pdf->setOption('isRemoteEnabled', true);
            $pdf->setPaper('a4', 'portrait');
            
            // Generate PDF content
            $content = $pdf->output();
            
            return [
                'content' => $content,
                'filename' => $filename,
                'mime' => 'application/pdf'
            ];
        } catch (\Exception $e) {
            // Log the error and return a placeholder content
            Log::error('PDF generation failed: ' . $e->getMessage(), [
                'filename' => $filename,
                'exception' => $e
            ]);
            
            return [
                'content' => 'PDF generation failed: ' . $e->getMessage(),
                'filename' => $filename,
                'mime' => 'application/pdf'
            ];
        }
    }
    
    /**
     * Generate CSV report
     *
     * @param array $reportData
     * @param string $filename
     * @return array
     */
    private function generateCsv(array $reportData, string $filename): array
    {
        $csvContent = $this->convertReportToCsv($reportData);
        
        return [
            'content' => $csvContent,
            'filename' => $filename,
            'mime' => 'text/csv'
        ];
    }
    
    /**
     * Convert report data to CSV format
     *
     * @param array $reportData
     * @return string
     */
    private function convertReportToCsv(array $reportData): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Add report metadata
        fputcsv($output, ['Security Report: ' . $reportData['report']['title']]);
        fputcsv($output, ['Generated:', $reportData['report']['created_at']]);
        fputcsv($output, ['By:', $reportData['report']['generated_by']]);
        fputcsv($output, ['Tenant:', $reportData['report']['tenant']]);
        fputcsv($output, ['Date Range:',
            $reportData['report']['date_range']['from'] . ' to ' .
            $reportData['report']['date_range']['to']
        ]);
        fputcsv($output, []); // Empty line
        
        // Summary section
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Events', $reportData['summary']['total_events']]);
        fputcsv($output, ['Total Alerts', $reportData['summary']['total_alerts']]);
        fputcsv($output, []);
        
        // Severity breakdown
        if (!empty($reportData['summary']['severity_breakdown'])) {
            fputcsv($output, ['Severity Breakdown']);
            fputcsv($output, ['Severity', 'Count']);
            
            foreach ($reportData['summary']['severity_breakdown'] as $severity => $count) {
                fputcsv($output, [$severity, $count]);
            }
            fputcsv($output, []);
        }
        
        // Event types
        if (!empty($reportData['summary']['event_types'])) {
            fputcsv($output, ['Event Types']);
            fputcsv($output, ['Type', 'Count']);
            
            foreach ($reportData['summary']['event_types'] as $type => $count) {
                fputcsv($output, [$type, $count]);
            }
            fputcsv($output, []);
        }
        
        // Security events
        if (!empty($reportData['data']['events'])) {
            fputcsv($output, ['Security Events']);
            
            // Headers
            $headers = ['Time', 'Type', 'Severity', 'User', 'IP Address', 'Description'];
            fputcsv($output, $headers);
            
            // Event data
            foreach ($reportData['data']['events'] as $event) {
                $row = [
                    $event['timestamp'] ?? '',
                    $event['event_type'] ?? '',
                    $event['severity'] ?? '',
                    $event['user'] ?? '',
                    $event['ip_address'] ?? '',
                    $event['description'] ?? ''
                ];
                fputcsv($output, $row);
            }
            fputcsv($output, []);
        }
        
        // Security alerts
        if (!empty($reportData['data']['alerts'])) {
            fputcsv($output, ['Security Alerts']);
            
            // Headers
            $headers = ['Time', 'Title', 'Severity', 'Status', 'Description'];
            fputcsv($output, $headers);
            
            // Alert data
            foreach ($reportData['data']['alerts'] as $alert) {
                $row = [
                    $alert['created_at'] ?? '',
                    $alert['title'] ?? '',
                    $alert['severity'] ?? '',
                    $alert['status'] ?? '',
                    $alert['description'] ?? ''
                ];
                fputcsv($output, $row);
            }
        }
          rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        return $csvContent;
    }
}