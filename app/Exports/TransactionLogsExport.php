<?php

namespace App\Exports;

use App\Models\Transaction;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TransactionLogsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        return Transaction::query()
            ->with(['terminal', 'tenant'])
            ->when($this->filters['status'] ?? null, function($query, $status) {
                $query->where('validation_status', $status);
            })
            ->when($this->filters['date'] ?? null, function($query, $date) {
                $query->whereDate('created_at', $date);
            });
    }

    public function headings(): array
    {
        return [
            'Transaction ID',
            'Terminal',
            'Amount',
            'Validation Status',
            'Job Status',
            'Attempts',
            'Created At'
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->transaction_id,
            $transaction->terminal->identifier ?? 'N/A',
            number_format($transaction->gross_sales, 2),
            $transaction->validation_status,
            $transaction->job_status,
            $transaction->job_attempts,
            $transaction->created_at->format('Y-m-d H:i:s')
        ];
    }
}