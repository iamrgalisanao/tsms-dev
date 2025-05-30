<?php

namespace App\Exports;

use App\Models\Log;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LogExport implements FromCollection, WithHeadings
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $query = Log::query();
        
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

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Type',
            'Severity',
            'Message',
            'Context',
            'Created At',
            'Updated At'
        ];
    }
}