<?php

namespace App\Exports;

use App\Models\PosTerminal;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProviderTerminalsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $providerId;
    
    public function __construct($providerId)
    {
        $this->providerId = $providerId;
    }
    
    public function query()
    {
        return PosTerminal::query()
            ->where('provider_id', $this->providerId)
            ->with(['tenant:id,name']);
    }
    
    public function headings(): array
    {
        return [
            'Terminal ID',
            'Tenant',
            'Status',
            'Registration Date',
            'Enrollment Date'
        ];
    }
    
    public function map($terminal): array
    {
        return [
            $terminal->terminal_uid,
            $terminal->tenant->name ?? 'Unknown',
            ucfirst($terminal->status),
            $terminal->registered_at->format('Y-m-d'),
            $terminal->enrolled_at->format('Y-m-d')
        ];
    }
}
