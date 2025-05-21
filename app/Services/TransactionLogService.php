<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\TransactionLogsExport;

class TransactionLogService
{
    public function getPaginatedLogs(array $filters = [])
    {
        $query = Transaction::with(['tenant', 'terminal'])
            ->select('transactions.*')
            ->when(isset($filters['status']), function($q) use ($filters) {
                $q->where('validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function($q) use ($filters) {
                $q->where('created_at', '>=', $filters['date_from']);
            })
            ->latest();

        return $query->paginate(50);
    }

    public function getLogDetail($id)
    {
        return Cache::remember("transaction_log.{$id}", 300, function() use ($id) {
            return Transaction::with([
                'tenant', 
                'terminal',
                'retryHistory',
                'validationLogs'
            ])->findOrFail($id);
        });
    }

    public function getLogWithHistory($id)
    {
        return Cache::remember("transaction_log.{$id}", 300, function() use ($id) {
            return Transaction::with([
                'processingHistory' => function($query) {
                    $query->orderBy('created_at', 'desc');
                }
            ])->findOrFail($id);
        });
    }

    public function exportLogs(array $filters)
    {
        $logs = $this->getPaginatedLogs($filters, false);
        
        return Excel::download(
            new TransactionLogsExport($logs),
            'transaction-logs-' . now()->format('Y-m-d') . '.xlsx'
        );
    }
}