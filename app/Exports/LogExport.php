<?php

namespace App\Exports;

use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LogExport
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection(): Collection
    {
        $query = SystemLog::query()->with('posTerminal');
        
        // Apply filters from request
        if ($this->request->filled('type')) {
            $query->where('type', $this->request->type);
        }
        if ($this->request->filled('severity')) {
            $query->where('severity', $this->request->severity);
        }
        if ($this->request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $this->request->date_from);
        }
        if ($this->request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $this->request->date_to);
        }

        return $query->latest()->limit(5000)->get();
    }

    public function headings(): array
    {
        return [
            'Time',
            'Type',
            'Severity',
            'Terminal',
            'Transaction ID',
            'Message',
            'Context',
        ];
    }

    public function row(SystemLog $log): array
    {
        return [
            optional($log->created_at)->format('Y-m-d H:i:s'),
            $log->log_type ?? $log->type ?? 'general',
            strtoupper($log->severity ?? 'info'),
            $log->posTerminal->serial_number ?? $log->terminal_uid ?? 'N/A',
            $log->transaction_id ?? 'N/A',
            $log->message ?? '',
            json_encode($log->context ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
