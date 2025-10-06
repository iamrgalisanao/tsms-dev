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
        return Cache::remember($this->getCacheKey($filters), 300, function() use ($filters) {
            return Transaction::with(['terminal.provider', 'tenant'])
                ->when($filters['date_from'] ?? null, function($q, $date) {
                    // Prefer filtering by the canonical transaction timestamp when available,
                    // otherwise fall back to created_at to preserve existing behavior.
                    $q->where(function($q) use ($date) {
                        $q->whereDate('transaction_timestamp', '>=', $date)
                          ->orWhereDate('created_at', '>=', $date);
                    });
                })
                ->when($filters['date_to'] ?? null, function($q, $date) {
                    $q->where(function($q) use ($date) {
                        $q->whereDate('transaction_timestamp', '<=', $date)
                          ->orWhereDate('created_at', '<=', $date);
                    });
                })
                ->when($filters['amount_min'] ?? null, fn($q, $amount) => 
                    $q->where('gross_sales', '>=', $amount))
                ->when($filters['amount_max'] ?? null, fn($q, $amount) => 
                    $q->where('gross_sales', '<=', $amount))
                ->when($filters['provider_id'] ?? null, fn($q, $id) => 
                    $q->whereHas('terminal', fn($q) => $q->where('provider_id', $id)))
                ->when($filters['terminal_id'] ?? null, fn($q, $id) => 
                    $q->where('terminal_id', $id))
                ->latest()
                ->paginate(15)
                ->appends($filters);
        });
    }

    protected function getCacheKey($filters)
    {
        return 'transaction_logs:' . md5(serialize($filters));
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
        return Cache::remember("transaction.log.{$id}", 300, function () use ($id) {
            return Transaction::with([
                'terminal',
                'tenant',
                'processingHistory' => fn($q) => $q->orderBy('created_at', 'desc')
            ])->findOrFail($id);
        });
    }

    public function exportLogs(array $filters)
    {
        $query = Transaction::query()
            ->with(['terminal', 'tenant'])
            ->when($filters['status'] ?? null, function($q, $status) {
                $q->where('validation_status', $status);
            })
            ->when($filters['date'] ?? null, function($q, $date) {
                $q->where(function($q) use ($date) {
                    $q->whereDate('transaction_timestamp', $date)
                      ->orWhereDate('created_at', $date);
                });
            });

        return Excel::download(new TransactionLogsExport($query), 'transaction-logs-' . now()->format('Y-m-d') . '.xlsx');
    }

    public function getUpdatesAfter($lastId)
    {
        return Cache::remember("updates.after.{$lastId}", 30, function() use ($lastId) {
            return Transaction::where('id', '>', $lastId)
                ->with(['terminal', 'tenant'])
                ->latest()
                ->limit(50)
                ->get();
        });
    }
}