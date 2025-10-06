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
                // Prefer transaction_timestamp for export filters when present
                $query->where(function($q) use ($date) {
                    $q->whereDate('transaction_timestamp', $date)
                      ->orWhereDate('created_at', $date);
                });
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
            'Transaction Timestamp',
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
            $transaction->transaction_timestamp ? $transaction->transaction_timestamp->format('Y-m-d H:i:s') : null,
            $transaction->created_at->format('Y-m-d H:i:s')
        ];
    }
}