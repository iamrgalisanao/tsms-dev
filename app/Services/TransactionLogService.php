<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    public function exportLogs(array $filters)
    {
        // Implementation for export functionality
    }
}