<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;

class TransactionDetailService
{
    public function getTransactionDetails($id)
    {
        return Cache::remember("transaction.{$id}.details", 300, function() use ($id) {
            try {
                return Transaction::with([
                    'terminal.provider', 
                    'tenant',
                    // 'processingHistory', // optional
                ])->findOrFail($id);
            } catch (\Throwable $e) {
                return Transaction::with([
                    'terminal.provider',
                    'tenant',
                ])->findOrFail($id);
            }
        });
    }

    public function getProcessingTimeline($transaction)
    {
        try {
            if (method_exists($transaction, 'processingHistory')) {
                return $transaction->processingHistory()
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(function($history) {
                        return [
                            'status' => $history->status,
                            'message' => $history->message,
                            'timestamp' => $history->created_at->format('Y-m-d H:i:s'),
                            'attempt' => $history->attempt_number,
                            'metadata' => $history->metadata ?? [],
                            'duration' => $history->duration ?? null,
                            'status_color' => $this->getStatusColor($history->status)
                        ];
                    });
            }
        } catch (\Throwable $e) {
            // no-op fallback
        }
        return collect([]);
    }

    private function getStatusColor($status)
    {
        return match(strtoupper($status)) {
            'COMPLETED', 'SUCCESS' => 'success',
            'ERROR', 'FAILED' => 'danger',
            'PROCESSING' => 'info',
            'QUEUED' => 'secondary',
            'RETRY' => 'warning',
            default => 'secondary'
        };
    }

    public function getDetailedMetrics($transaction)
    {
        return [
            'processing_time' => $this->calculateProcessingTime($transaction),
            'retry_count' => (int) ($transaction->job_attempts ?? 0),
            'first_attempt' => optional($transaction->created_at)->format('Y-m-d H:i:s'),
            'last_attempt' => optional($transaction->updated_at)->format('Y-m-d H:i:s'),
            'success_rate' => $this->calculateSuccessRate($transaction->terminal_id)
        ];
    }

    private function calculateProcessingTime($transaction)
    {
        if (!$transaction->completed_at || !$transaction->created_at) return null;
        try {
            return $transaction->completed_at->diffInSeconds($transaction->created_at);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function calculateSuccessRate($terminalId)
    {
        $total = Transaction::where('terminal_id', $terminalId)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($total === 0) return 0;

        $success = Transaction::where('terminal_id', $terminalId)
            ->where('created_at', '>=', now()->subDays(7))
            ->where('validation_status', 'VALID')
            ->count();

        return round(($success / $total) * 100, 2);
    }

    public function getTransactionTimeline($transaction)
    {
        try {
            if (method_exists($transaction, 'processingHistory')) {
                return $transaction->processingHistory()
                    ->with('user')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->map(function($history) {
                        return [
                            'status' => $history->status,
                            'message' => $history->message,
                            'timestamp' => $history->created_at,
                            'attempt' => $history->attempt_number,
                            'user' => $history->user ? $history->user->name : 'System',
                            'metadata' => $history->metadata ?? []
                        ];
                    });
            }
        } catch (\Throwable $e) {
            // no-op
        }
        return collect([]);
    }

    public function getRelatedTransactions($transaction)
    {
        return Transaction::where('terminal_id', $transaction->terminal_id)
            ->where('id', '!=', $transaction->id)
            ->latest()
            ->limit(5)
            ->get();
    }

    public function getTransactionAnalytics($transaction)
    {
        return [
            'processing_duration' => $this->calculateProcessingDuration($transaction),
            'retry_statistics' => $this->getRetryStatistics($transaction),
            'error_frequency' => $this->calculateErrorFrequency($transaction->terminal_id),
            'performance_metrics' => $this->getPerformanceMetrics($transaction)
        ];
    }

    private function calculateErrorFrequency($terminalId)
    {
        $thirtyDaysAgo = now()->subDays(30);
        
        return Transaction::where('terminal_id', $terminalId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN validation_status = "ERROR" THEN 1 ELSE 0 END) as errors')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();
    }

    private function getPerformanceMetrics($transaction)
    {
        return [
            'average_processing_time' => $this->getAverageProcessingTime($transaction->terminal_id),
            'success_rate_trend' => $this->getSuccessRateTrend($transaction->terminal_id),
            'peak_hours' => $this->getPeakTransactionHours($transaction->terminal_id)
        ];
    }
}