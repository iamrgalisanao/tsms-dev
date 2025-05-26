<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SystemLogsExport;
use PDF;

class LogExportService
{
    public function export(string $format, array $filters = []): mixed
    {
        $logs = $this->getFilteredLogs($filters);
        $filename = 'system-logs-' . now()->format('Y-m-d');

        return match($format) {
            'pdf' => $this->exportToPDF($logs, $filename),
            'csv' => $this->exportToCSV($logs, $filename),
            default => throw new \InvalidArgumentException('Unsupported export format')
        };
    }

    private function getFilteredLogs(array $filters): Collection
    {
        return SystemLog::query()
            ->when(isset($filters['type']), fn($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['severity']), fn($q) => $q->where('severity', $filters['severity']))
            ->when(isset($filters['date']), fn($q) => $q->whereDate('created_at', $filters['date']))
            ->when(isset($filters['search']), fn($q) => $q->where('transaction_id', 'like', "%{$filters['search']}%"))
            ->latest()
            ->get();
    }

    private function exportToPDF(Collection $logs, string $filename): mixed
    {
        return PDF::loadView('exports.logs-pdf', ['logs' => $logs])
            ->download($filename . '.pdf');
    }

    private function exportToCSV(Collection $logs, string $filename): mixed
    {
        return Excel::download(new SystemLogsExport($logs), $filename . '.csv');
    }
}
