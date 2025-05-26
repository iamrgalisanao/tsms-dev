<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SystemLogsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $logs;

    public function __construct(Collection $logs)
    {
        $this->logs = $logs;
    }

    public function collection(): Collection
    {
        return $this->logs;
    }

    public function headings(): array
    {
        return [
            'Time',
            'Type',
            'Severity',
            'Message',
            'Terminal',
            'Transaction ID',
            'Status'
        ];
    }

    public function map($log): array
    {
        return [
            $log->created_at->format('Y-m-d H:i:s'),
            $log->log_type,
            strtoupper($log->severity),
            $log->message,
            $log->terminal_uid ?? 'N/A',
            $log->transaction_id ?? 'N/A',
            $log->status
        ];
    }
}