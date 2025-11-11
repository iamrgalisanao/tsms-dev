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
                // Default to VALID transactions unless an explicit status
                // filter is provided. Only apply this default when the
                // schema contains a receipt_no column so environments that
                // don't have that column (tests/older schemas) preserve
                // legacy behavior.
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'receipt_no') && !isset($filters['status']), function($q) {
                    // Exclude DUPLICATE rows by default so listings/summary counts
                    // align with POS unique-receipt counts without hiding PENDING/ERROR rows.
                    $q->where('validation_status', '!=', 'DUPLICATE');
                })
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
            // Default to VALID when no status filter supplied, but only
            // if receipt_no exists in the schema. Otherwise preserve legacy
            // behavior and don't filter by validation_status.
            ->when(isset($filters['status']), function($q) use ($filters) {
                $q->where('validation_status', $filters['status']);
            }, function($q) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'receipt_no')) {
                    $q->where('validation_status', '!=', 'DUPLICATE');
                }
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
            // Only include VALID transactions by default to keep live updates
            // consistent with the reporting filter (Option A), but only when
            // receipt_no exists in the schema. Otherwise include all rows.
            $query = Transaction::where('id', '>', $lastId)->with(['terminal', 'tenant']);
            if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'receipt_no')) {
                $query->where('validation_status', '!=', 'DUPLICATE');
            }
            return $query->latest()->limit(50)->get();
        });
    }
}