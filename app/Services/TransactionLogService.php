<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Exports\TransactionLogsExport;

class TransactionLogService
{
    public function getPaginatedLogs(array $filters = [])
    {
        return Cache::remember($this->getCacheKey($filters), 300, function() use ($filters) {
            return Transaction::with(['terminal.provider', 'tenant'])
                ->when($filters['date_from'] ?? null, fn($q, $date) =>
                    $q->where('created_at', '>=', $date . ' 00:00:00'))
                ->when($filters['date_to'] ?? null, fn($q, $date) =>
                    $q->where('created_at', '<=', $date . ' 23:59:59'))
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
        return (new TransactionLogsExport($filters))->download('transaction-logs-' . now()->format('Y-m-d') . '.xlsx');
    }

    public function getUpdatesAfter($lastId)
    {
        $user = Auth::user();
        $isAdmin = $user && method_exists($user, 'hasRole') && $user->hasRole('admin');
        $hasTenant = $user && isset($user->tenant_id) && $user->tenant_id !== null;

        if (! $user || (! $isAdmin && ! $hasTenant)) {
            return collect();
        }

        return Cache::remember($this->getUpdatesCacheKey($lastId), 30, function() use ($lastId) {
            return Transaction::where('id', '>', $lastId)
                ->with(['terminal', 'tenant'])
                ->latest()
                ->limit(50)
                ->get();
        });
    }

    protected function getUpdatesCacheKey($lastId): string
    {
        $user = Auth::user();

        if (! $user) {
            return "updates.after.{$lastId}.guest";
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return "updates.after.{$lastId}.unscoped";
        }

        if (isset($user->tenant_id) && $user->tenant_id !== null) {
            return "updates.after.{$lastId}.tenant.{$user->tenant_id}";
        }

        return "updates.after.{$lastId}.deny";
    }
}
