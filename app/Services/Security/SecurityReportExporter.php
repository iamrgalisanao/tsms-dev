<?php

namespace App\Services\Security;

use App\Models\SecurityReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class SecurityReportExporter
{
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    private const EXPORT_PATH = 'exports/security';

    /**
     * Export a report in the specified format
     *
     * @param int $reportId
     * @param string $format
     * @return string|null The path to the exported file or null on failure
     */
    public function exportReport(int $reportId, string $format = 'pdf'): ?string
    {
        try {
            $report = SecurityReport::findOrFail($reportId);
            $dateStr = now()->format('Y-m-d-His');
            $filename = sprintf(
                '%s-%s.%s',
                Str::slug($report->name),
                $dateStr,
                $format
            );

            Storage::makeDirectory(self::EXPORT_PATH);

            $exported = match ($format) {
                'pdf' => $this->exportToPDF($report),
                'csv' => $this->exportToCSV($report),
                'html' => $this->exportToHTML($report),
                default => throw new \InvalidArgumentException("Unsupported format: {$format}")
            };

            if (!$exported || !file_exists($exported)) {
                Log::error('Failed to generate export file', [
                    'report_id' => $reportId,
                    'format' => $format
                ]);
                return null;
            }

            return $exported;
        } catch (\Exception $e) {
            Log::error('Export failed', [
                'report_id' => $reportId,
                'format' => $format,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Export report as PDF
     *
     * @param SecurityReport $report
     * @return string|null The path to the PDF file
     */
    public function exportToPDF(SecurityReport $report): ?string
    {
        try {
            $filename = sprintf(
                '%s/%s-%s.pdf',
                self::EXPORT_PATH,
                Str::slug($report->name),
                now()->format('Y-m-d-His')
            );

            $pdf = PDF::loadView('reports.security.pdf', ['report' => $report]);
            Storage::put($filename, $pdf->output());

            return Storage::path($filename);
        } catch (\Exception $e) {
            Log::error('PDF export failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Export report as CSV
     *
     * @param SecurityReport $report
     * @return string|null The path to the CSV file
     */
    public function exportToCSV(SecurityReport $report): ?string
    {
        try {
            $filename = sprintf(
                '%s/%s-%s.csv',
                self::EXPORT_PATH,
                Str::slug($report->name),
                now()->format('Y-m-d-His')
            );

            $handle = fopen('php://temp', 'r+');

            // Write headers
            fputcsv($handle, ['Report Name', $report->name]);
            fputcsv($handle, ['Generated', now()->format(self::DATE_TIME_FORMAT)]);
            fputcsv($handle, ['Period', $report->from_date . ' to ' . $report->to_date]);
            fputcsv($handle, []);

            // Write events section
            if (!empty($report->events)) {
                fputcsv($handle, ['Security Events']);
                fputcsv($handle, ['Time', 'Type', 'Severity', 'Description', 'Source']);

                foreach ($report->events as $event) {
                    fputcsv($handle, [
                        $event->created_at,
                        $event->type,
                        $event->severity,
                        $event->description,
                        $event->source_ip
                    ]);
                }
                fputcsv($handle, []);
            }

            // Write alerts section
            if (!empty($report->alerts)) {
                fputcsv($handle, ['Security Alerts']);
                fputcsv($handle, ['Time', 'Rule', 'Severity', 'Status', 'Actions Taken']);

                foreach ($report->alerts as $alert) {
                    fputcsv($handle, [
                        $alert->created_at,
                        $alert->rule->name,
                        $alert->severity,
                        $alert->status,
                        $alert->actions_taken
                    ]);
                }
            }

            rewind($handle);
            Storage::put($filename, stream_get_contents($handle));
            fclose($handle);

            return Storage::path($filename);
        } catch (\Exception $e) {
            Log::error('CSV export failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Export report as HTML
     *
     * @param SecurityReport $report
     * @return string|null The path to the HTML file
     */
    public function exportToHTML(SecurityReport $report): ?string
    {
        try {
            $filename = sprintf(
                '%s/%s-%s.html',
                self::EXPORT_PATH,
                Str::slug($report->name),
                now()->format('Y-m-d-His')
            );

            $content = view('reports.security.html', ['report' => $report])->render();
            Storage::put($filename, $content);

            return Storage::path($filename);
        } catch (\Exception $e) {
            Log::error('HTML export failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}