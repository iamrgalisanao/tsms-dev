<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Collection;
use PDF;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SystemLogsExport;

class SystemLogExportService
{
    public function export(string $format, array $filters = []): mixed
    {
        $logs = $this->getFilteredLogs($filters);
        $filename = 'system-logs-' . now()->format('Y-m-d');

        return match($format) {
            'pdf' => $this->exportPDF($logs, $filename),
            'csv' => $this->exportCSV($logs, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format')
        };
    }

    private function getFilteredLogs(array $filters): Collection
    {
        return SystemLog::query()
            ->when(isset($filters['type']), fn($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['severity']), fn($q) => $q->where('severity', $filters['severity']))
            ->when(isset($filters['date']), fn($q) => $q->whereDate('created_at', $filters['date']))
            ->latest()
            ->get();
    }

    private function exportPDF(Collection $logs, string $filename): mixed
    {
        return PDF::loadView('exports.logs-pdf', compact('logs'))
            ->download($filename . '.pdf');
    }

    private function exportCSV(Collection $logs, string $filename): mixed
    {
        return Excel::download(new SystemLogsExport($logs), $filename . '.csv');
    }
}
