<?php

namespace App\Services\Security;

use App\Models\SecurityReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use App\Exceptions\SecurityReportExportException;

class SecurityReportExportService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const ALLOWED_FORMATS = ['pdf', 'csv'];

    public function __construct(
        private readonly CsvExportService $csvExporter = new CsvExportService(),
        private readonly PdfExportService $pdfExporter = new PdfExportService()
    ) {}

    public function exportReport(SecurityReport $report, string $format = 'pdf'): array
    {
        try {
            if (!in_array($format, self::ALLOWED_FORMATS, true)) {
                throw new SecurityReportExportException('Invalid export format');
            }

            $reportData = $this->prepareReportData($report);
            $filename = $this->generateFilename($report, $format);

            return match($format) {
                'pdf' => $this->pdfExporter->generate($filename, $reportData),
                'csv' => $this->csvExporter->generate($reportData, $filename),
                default => throw new SecurityReportExportException('Invalid export format'),
            };
        } catch (\Exception $e) {
            Log::error('Report export failed', [
                'report_id' => $report->id,
                'format' => $format,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new SecurityReportExportException('Failed to export report: ' . $e->getMessage());
        }
    }

    private function generateFilename(SecurityReport $report, string $format): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $report->name ?? '');
        $timestamp = now()->format('Y-m-d_H-i-s');
        return sprintf('security_report_%s_%s.%s', $safeName, $timestamp, $format);
    }

    private function prepareReportData(SecurityReport $report): array
    {
        try {
            if (!$report->relationLoaded('generatedBy') || !$report->relationLoaded('tenant')) {
                $report->load(['generatedBy', 'tenant']);
            }

            $reportData = json_decode($report->results, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to decode report results', [
                    'report_id' => $report->id,
                    'error' => json_last_error_msg()
                ]);
                $reportData = [];
            }

            $fromDate = $report->from_date
                ? $report->from_date->format(self::DATETIME_FORMAT)
                : null;
            $toDate = $report->to_date
                ? $report->to_date->format(self::DATETIME_FORMAT)
                : null;

            return [
                'report' => [
                    'id' => $report->id,
                    'name' => $report->name ?? 'Untitled Report',
                    'description' => $report->description ?? '',
                    'created_at' => $report->created_at
                        ? $report->created_at->format(self::DATETIME_FORMAT)
                        : now()->format(self::DATETIME_FORMAT),
                    'generated_by' => $report->generatedBy
                        ? $report->generatedBy->name
                        : 'System',
                    'tenant' => $report->tenant
                        ? $report->tenant->name
                        : 'System',
                    'date_range' => ['from' => $fromDate, 'to' => $toDate],
                ],
                'summary' => [
                    'total_events' => (int)($reportData['total_events'] ?? 0),
                    'total_alerts' => (int)($reportData['total_alerts'] ?? 0),
                    'severity_breakdown' => $reportData['severity_breakdown'] ?? [],
                    'event_types' => $reportData['event_types'] ?? [],
                ],
                'data' => [
                    'events' => $this->sanitizeEvents($reportData['events'] ?? []),
                    'alerts' => $this->sanitizeAlerts($reportData['alerts'] ?? [])
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to prepare report data', [
                'report_id' => $report->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new SecurityReportExportException(
                'Failed to prepare report data: ' . $e->getMessage()
            );
        }
    }

    private function sanitizeEvents(array $events): array
    {
        $charset = 'UTF-8';
        return array_map(function($event) use ($charset) {
            $normalize = $this->getNormalizer($charset);

            return [
                'timestamp' => $event['timestamp'] ?? '',
                'event_type' => htmlspecialchars($normalize($event['event_type']), ENT_QUOTES | ENT_HTML5, $charset),
                'severity' => $event['severity'] ?? '',
                'user' => htmlspecialchars($normalize($event['user']), ENT_QUOTES | ENT_HTML5, $charset),
                'ip_address' => filter_var($event['ip_address'] ?? '', FILTER_VALIDATE_IP) ?: '',
                'description' => htmlspecialchars($normalize($event['description']), ENT_QUOTES | ENT_HTML5, $charset)
            ];
        }, $events);
    }

    private function sanitizeAlerts(array $alerts): array
    {
        $charset = 'UTF-8';
        return array_map(function($alert) use ($charset) {
            $normalize = $this->getNormalizer($charset);

            $validStatuses = ['Open', 'Closed', 'In Progress'];
            $normalizedStatus = ucfirst(strtolower($alert['status'] ?? ''));
            $status = in_array($normalizedStatus, $validStatuses) ? $normalizedStatus : 'Open';

            return [
                'created_at' => $alert['created_at'] ?? '',
                'title' => htmlspecialchars($normalize($alert['title']), ENT_QUOTES | ENT_HTML5, $charset),
                'severity' => ucfirst(strtolower($alert['severity'] ?? 'medium')),
                'status' => $status,
                'description' => htmlspecialchars($normalize($alert['description']), ENT_QUOTES | ENT_HTML5, $charset)
            ];
        }, $alerts);
    }

    private function getNormalizer(string $charset): callable
    {
        return function($str) use ($charset) {
            if (!is_string($str)) {
                return '';
            }
            $encoding = mb_detect_encoding($str, 'UTF-8, ISO-8859-1', true);
            if ($encoding !== $charset) {
                $str = mb_convert_encoding($str, $charset, $encoding);
            }
            $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $str);
            return preg_replace('/\R/u', "\n", trim($str));
        };
    }
}