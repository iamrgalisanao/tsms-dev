<?php

namespace App\Services\Security;

use App\Exceptions\SecurityReportExportException;
use Illuminate\Support\Facades\Log;

class SecurityReportCsvWriter
{
    private const BATCH_SIZE = 1000;

    public function write($output, array $reportData): void
    {
        try {
            fputs($output, "\xEF\xBB\xBF"); // Add BOM for Excel UTF-8 compatibility
            
            $this->writeMetadata($output, $reportData);
            $this->writeSummary($output, $reportData);
            $this->writeEventTypes($output, $reportData);
            $this->writeEventsBatched($output, $reportData);
            $this->writeAlertsBatched($output, $reportData);
        } catch (\Exception $e) {
            throw new SecurityReportExportException(
                'Failed to write CSV data: ' . $e->getMessage(),
                $reportData['report']['id'] ?? '',
                'csv',
                0,
                $e
            );
        }
    }

    private function writeMetadata($output, array $reportData): void
    {
        $this->writeRow($output, ['Security Report: ' . $reportData['report']['name']]);
        $this->writeRow($output, ['Generated:', $reportData['report']['created_at']]);
        $this->writeRow($output, ['By:', $reportData['report']['generated_by']]);
        $this->writeRow($output, ['Tenant:', $reportData['report']['tenant']]);

        if ($reportData['report']['date_range']['from'] && $reportData['report']['date_range']['to']) {
            $dateRange = $reportData['report']['date_range']['from'] . ' to ' . $reportData['report']['date_range']['to'];
            $this->writeRow($output, ['Date Range:', $dateRange]);
        }

        $this->writeRow($output, []);
    }

    private function writeSummary($output, array $reportData): void
    {
        $this->writeRow($output, ['Total Events', (string)$reportData['summary']['total_events']]);
        $this->writeRow($output, ['Total Alerts', (string)$reportData['summary']['total_alerts']]);
        $this->writeRow($output, []);

        if (!empty($reportData['summary']['severity_breakdown'])) {
            $this->writeRow($output, ['Severity Breakdown']);
            $this->writeRow($output, ['Severity', 'Count']);
            foreach ($reportData['summary']['severity_breakdown'] as $severity => $count) {
                $this->writeRow($output, [$severity, (string)$count]);
            }
            $this->writeRow($output, []);
        }
    }

    private function writeEventTypes($output, array $reportData): void
    {
        if (empty($reportData['summary']['event_types'])) {
            return;
        }

        $this->writeRow($output, ['Event Types']);
        $this->writeRow($output, ['Type', 'Count']);
        foreach ($reportData['summary']['event_types'] as $type => $count) {
            $this->writeRow($output, [$type, (string)$count]);
        }
        $this->writeRow($output, []);
    }

    private function writeEventsBatched($output, array $reportData): void
    {
        if (empty($reportData['data']['events'])) {
            return;
        }

        $this->writeRow($output, ['Security Events']);
        $this->writeRow($output, ['Time', 'Type', 'Severity', 'User', 'IP Address', 'Description']);

        $this->processBatch($output, $reportData['data']['events'], function($output, $event) {
            $this->writeRow($output, [
                $event['timestamp'],
                $event['event_type'],
                $event['severity'],
                $event['user'],
                $event['ip_address'],
                $event['description']
            ]);
        });

        $this->writeRow($output, []);
    }

    private function writeAlertsBatched($output, array $reportData): void
    {
        if (empty($reportData['data']['alerts'])) {
            return;
        }

        $this->writeRow($output, ['Security Alerts']);
        $this->writeRow($output, ['Time', 'Title', 'Severity', 'Status', 'Description']);

        $this->processBatch($output, $reportData['data']['alerts'], function($output, $alert) {
            $this->writeRow($output, [
                $alert['created_at'],
                $alert['title'],
                $alert['severity'],
                $alert['status'],
                $alert['description']
            ]);
        });

        $this->writeRow($output, []);
    }

    private function processBatch($output, array $items, callable $processor): void
    {
        $batch = [];
        $count = 0;

        foreach ($items as $item) {
            $processor($output, $item);
            $count++;

            if ($count % self::BATCH_SIZE === 0) {
                fflush($output);
            }
        }

        if ($count > 0) {
            fflush($output);
        }
    }

    private function writeRow($output, array $row): void
    {
        fputcsv($output, array_map(function($value) {
            return is_string($value) ? $value : (string)$value;
        }, $row));
    }
}
