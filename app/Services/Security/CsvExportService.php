<?php

namespace App\Services\Security;

use App\Exceptions\SecurityReportExportException;
use Illuminate\Support\Facades\Log;

class CsvExportService
{
    public function generate(array $reportData, string $filename): array
    {
        try {
            return $this->generateCsvWithBatching($reportData, $filename);
        } catch (\Exception $e) {
            Log::error('CSV generation failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new SecurityReportExportException('Failed to generate CSV: ' . $e->getMessage());
        }
    }

    private function generateCsvWithBatching(array $reportData, string $filename): array
    {
        $tempFile = $this->createTempFile();
        $output = fopen($tempFile, 'w+b');
        
        if ($output === false) {
            throw new SecurityReportExportException('Failed to create temporary file');
        }

        try {
            // Add BOM for Excel UTF-8 compatibility
            fputs($output, "\xEF\xBB\xBF");

            // Write sections
            $this->writeReportSections($output, $reportData);
            
            // Get content and clean up
            fflush($output);
            rewind($output);
            $csvContent = stream_get_contents($output);

            return [
                'content' => $csvContent,
                'filename' => $filename,
                'mime' => 'text/csv'
            ];
        } finally {
            fclose($output);
            unlink($tempFile);
        }
    }

    private function createTempFile(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'security_report_');
        if ($tempFile === false) {
            throw new SecurityReportExportException('Failed to create temporary file');
        }
        return $tempFile;
    }

    private function writeReportSections($output, array $reportData): void
    {
        $this->writeCsvMetadata($output, $reportData);
        $this->writeCsvSummary($output, $reportData);
        $this->writeCsvEventTypes($output, $reportData);
        
        if (!empty($reportData['data']['events'])) {
            $this->writeEventSection($output, $reportData);
        }

        if (!empty($reportData['data']['alerts'])) {
            $this->writeAlertSection($output, $reportData);
        }
    }

    private function writeEventSection($output, array $reportData): void
    {
        $this->writeCsvRow($output, ['Security Events']);
        $this->writeCsvRow($output, ['Time', 'Type', 'Severity', 'User', 'IP Address', 'Description']);
        
        $this->processBatches($output, $reportData['data']['events'], $this->writeCsvEventsBatch(...));
        $this->writeCsvRow($output, []);
    }

    private function writeAlertSection($output, array $reportData): void
    {
        $this->writeCsvRow($output, ['Security Alerts']);
        $this->writeCsvRow($output, ['Time', 'Title', 'Severity', 'Status', 'Description']);
        
        $this->processBatches($output, $reportData['data']['alerts'], $this->writeCsvAlertsBatch(...));
        $this->writeCsvRow($output, []);
    }

    private function writeCsvEventsBatch($output, array $batch): void
    {
        foreach ($batch as $event) {
            $this->writeCsvRow($output, [
                $event['timestamp'],
                $event['event_type'],
                $event['severity'],
                $event['user'],
                $event['ip_address'],
                $event['description']
            ]);
        }
    }

    private function writeCsvAlertsBatch($output, array $batch): void
    {
        foreach ($batch as $alert) {
            $this->writeCsvRow($output, [
                $alert['created_at'],
                $alert['title'],
                $alert['severity'],
                $alert['status'],
                $alert['description']
            ]);
        }
    }

    private function processBatches($output, array $items, callable $batchProcessor): void
    {
        $batch = [];
        $batchSize = 1000;

        foreach ($items as $item) {
            $batch[] = $item;
            if (count($batch) >= $batchSize) {
                $batchProcessor($output, $batch);
                $batch = [];
                fflush($output);
            }
        }

        if (!empty($batch)) {
            $batchProcessor($output, $batch);
        }
    }

    private function writeCsvRow($output, array $row): void
    {
        fputcsv($output, array_map(function($value) {
            return is_string($value) ? $value : (string)$value;
        }, $row));
    }

    private function writeCsvMetadata($output, array $reportData): void
    {
        $this->writeCsvRow($output, ['Report Metadata']);
        $metadata = $reportData['report'];
        $this->writeCsvRow($output, ['ID', $metadata['id']]);
        $this->writeCsvRow($output, ['Name', $metadata['name']]);
        $this->writeCsvRow($output, ['Description', $metadata['description']]);
        $this->writeCsvRow($output, ['Created At', $metadata['created_at']]);
        $this->writeCsvRow($output, ['Generated By', $metadata['generated_by']]);
        $this->writeCsvRow($output, ['Tenant', $metadata['tenant']]);
        $this->writeCsvRow($output, ['Date Range', $metadata['date_range']['from'] . ' to ' . $metadata['date_range']['to']]);
        $this->writeCsvRow($output, []);
    }

    private function writeCsvSummary($output, array $reportData): void
    {
        $this->writeCsvRow($output, ['Summary']);
        $summary = $reportData['summary'];
        $this->writeCsvRow($output, ['Total Events', $summary['total_events']]);
        $this->writeCsvRow($output, ['Total Alerts', $summary['total_alerts']]);
        $this->writeCsvRow($output, []);

        if (!empty($summary['severity_breakdown'])) {
            $this->writeCsvRow($output, ['Severity Breakdown']);
            foreach ($summary['severity_breakdown'] as $severity => $count) {
                $this->writeCsvRow($output, [$severity, $count]);
            }
            $this->writeCsvRow($output, []);
        }
    }

    private function writeCsvEventTypes($output, array $reportData): void
    {
        if (!empty($reportData['summary']['event_types'])) {
            $this->writeCsvRow($output, ['Event Types']);
            foreach ($reportData['summary']['event_types'] as $type => $count) {
                $this->writeCsvRow($output, [$type, $count]);
            }
            $this->writeCsvRow($output, []);
        }
    }
}
