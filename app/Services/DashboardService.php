<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\TerminalStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Build dashboard metrics payload used by /api/dashboard/metrics.
     */
    public function getAdvancedMetrics(array $filters = []): array
    {
        $now = Carbon::now();
        $todayStart = $this->resolveStart($filters['start_date'] ?? null, Carbon::today());
        $todayEnd = $this->resolveEnd($filters['end_date'] ?? null, Carbon::today()->endOfDay());
        $rangeDays = max(1, $todayStart->diffInDays($todayEnd) + 1);

        $previousStart = $todayStart->copy()->subDays($rangeDays);
        $previousEnd = $todayEnd->copy()->subDays($rangeDays);

        $dateColumn = $this->transactionDateColumn();
        $amountColumn = $this->transactionAmountColumn();
        $hasCompletedAt = Schema::hasColumn('transactions', 'completed_at');
        $hasValidationStatus = Schema::hasColumn('transactions', 'validation_status');
        $hasJobStatus = Schema::hasColumn('transactions', 'job_status');

        $currentRevenue = (float) Transaction::whereBetween($dateColumn, [$todayStart, $todayEnd])
            ->selectRaw("COALESCE(SUM($amountColumn), 0) as value")
            ->value('value');

        $previousRevenue = (float) Transaction::whereBetween($dateColumn, [$previousStart, $previousEnd])
            ->selectRaw("COALESCE(SUM($amountColumn), 0) as value")
            ->value('value');

        $currentTransactions = (int) Transaction::whereBetween($dateColumn, [$todayStart, $todayEnd])->count();
        $previousTransactions = (int) Transaction::whereBetween($dateColumn, [$previousStart, $previousEnd])->count();

        $currentVoids = 0;
        $previousVoids = 0;
        if (Schema::hasColumn('transactions', 'voided_at')) {
            $currentVoids = (int) Transaction::whereBetween('voided_at', [$todayStart, $todayEnd])->count();
            $previousVoids = (int) Transaction::whereBetween('voided_at', [$previousStart, $previousEnd])->count();
        }

        $currentVoidRate = $currentTransactions > 0 ? round(($currentVoids / $currentTransactions) * 100, 1) : 0.0;
        $previousVoidRate = $previousTransactions > 0 ? round(($previousVoids / $previousTransactions) * 100, 1) : 0.0;

        $activeStatusId = TerminalStatus::where('name', 'active')->value('id');
        $activeTerminals = $activeStatusId
            ? (int) PosTerminal::where('status_id', $activeStatusId)->count()
            : 0;
        $totalTerminals = (int) PosTerminal::count();

        $reconciledQuery = Transaction::whereBetween($dateColumn, [$todayStart, $todayEnd]);
        if ($hasValidationStatus) {
            $reconciledQuery->where('validation_status', Transaction::VALIDATION_STATUS_VALID);
        }
        if ($hasCompletedAt) {
            $reconciledQuery->whereNotNull('completed_at');
        }
        $reconciled = (int) $reconciledQuery->count();

        $pendingQuery = Transaction::whereBetween($dateColumn, [$todayStart, $todayEnd]);
        if ($hasValidationStatus || $hasCompletedAt) {
            $pendingQuery->where(function ($q) use ($hasValidationStatus, $hasCompletedAt) {
                if ($hasValidationStatus) {
                    $q->where('validation_status', Transaction::VALIDATION_STATUS_PENDING);
                }
                if ($hasCompletedAt) {
                    $hasValidationStatus ? $q->orWhereNull('completed_at') : $q->whereNull('completed_at');
                }
            });
        }
        $pending = (int) $pendingQuery->count();

        $failedQuery = Transaction::whereBetween($dateColumn, [$todayStart, $todayEnd]);
        if ($hasValidationStatus || $hasJobStatus) {
            $failedQuery->where(function ($q) use ($hasValidationStatus, $hasJobStatus) {
                if ($hasValidationStatus) {
                    $q->where('validation_status', 'INVALID')
                        ->orWhere('validation_status', Transaction::VALIDATION_STATUS_FAILED)
                        ->orWhere('validation_status', 'ERROR');
                }
                if ($hasJobStatus) {
                    $hasValidationStatus
                        ? $q->orWhere('job_status', Transaction::JOB_STATUS_FAILED)
                        : $q->where('job_status', Transaction::JOB_STATUS_FAILED);
                }
            });
        }
        $failed = (int) $failedQuery->count();

        return [
            'total_sales' => [
                'current' => round($currentRevenue, 2),
                'trend' => $this->percentDelta($currentRevenue, $previousRevenue),
                'sparkline' => $this->buildSparkline($rangeDays, 'sum', 'gross_sales', $todayEnd, $dateColumn),
            ],
            'total_transactions' => [
                'current' => $currentTransactions,
                'trend' => $this->percentDelta($currentTransactions, $previousTransactions),
                'sparkline' => $this->buildSparkline($rangeDays, 'count', '*', $todayEnd, $dateColumn),
            ],
            'voided_transactions' => [
                'current' => $currentVoids,
                'trend' => $this->percentDelta($currentVoids, $previousVoids),
            ],
            'void_rate' => [
                'current' => $currentVoidRate,
                'trend' => $this->percentDelta($currentVoidRate, $previousVoidRate),
            ],
            'active_terminals' => [
                'current' => $activeTerminals,
                'total' => $totalTerminals,
            ],
            'reconciliation' => [
                'reconciled' => $reconciled,
                'total' => $currentTransactions,
                'pending' => $pending,
                'failed' => $failed,
                'trend' => 0,
            ],
            'pending_uploads' => [
                'current' => $this->queueBacklog(),
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Build a lightweight system health payload used by /api/dashboard/system-health.
     */
    public function getSystemHealth(): array
    {
        $cpu = 0;
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpu = isset($load[0]) ? (int) min(100, max(0, round($load[0] * 25))) : 0;
        }

        $memoryLimit = ini_get('memory_limit');
        $limitMb = $this->memoryLimitToMb($memoryLimit);
        $usedMb = memory_get_usage(true) / 1024 / 1024;
        $memoryPct = $limitMb > 0 ? (int) min(100, round(($usedMb / $limitMb) * 100)) : 0;

        return [
            'cpu' => $cpu,
            'memory' => $memoryPct,
            'network' => 'Healthy',
            'queues' => [
                'backlog' => $this->queueBacklog(),
            ],
        ];
    }

    /**
     * Build terminal performance ranking used by /api/dashboard/terminal-performance.
     */
    public function getTerminalPerformance(): array
    {
        $dateColumn = $this->transactionDateColumn();
        $amountColumn = $this->transactionAmountColumn();

        $rows = DB::table('pos_terminals as pt')
            ->leftJoin('tenants as t', 't.id', '=', 'pt.tenant_id')
            ->leftJoin('transactions as tr', function ($join) use ($dateColumn) {
                $join->on('tr.terminal_id', '=', 'pt.id')
                    ->whereBetween("tr.$dateColumn", [Carbon::today(), Carbon::today()->endOfDay()]);
            })
            ->selectRaw("pt.id as terminal_id, pt.serial_number, COALESCE(t.trade_name, ?) as trade_name, COALESCE(SUM(tr.$amountColumn), 0) as total_sales", ['Unknown Tenant'])
            ->groupBy('pt.id', 'pt.serial_number', 't.trade_name')
            ->orderByDesc('total_sales')
            ->limit(20)
            ->get();

        return $rows->map(function ($row) {
            return [
                'terminal_id' => $row->terminal_id,
                'serial_number' => $row->serial_number,
                'trade_name' => $row->trade_name,
                'total_sales' => (float) $row->total_sales,
            ];
        })->values()->all();
    }

    public function getPerformanceMetrics($dateRange = 7)
    {
        $startDate = now()->subDays($dateRange);
        
        return [
            'total_transactions' => Transaction::where('created_at', '>=', $startDate)->count(),
            'success_rate' => $this->calculateSuccessRate($startDate),
            'avg_processing_time' => $this->calculateAvgProcessingTime($startDate),
            'error_rate' => $this->calculateErrorRate($startDate),
            'provider_stats' => $this->getProviderStats($startDate)
        ];
    }

    public function exportPerformanceReport($format, $dateRange, $startDate = null, $endDate = null)
    {
        // Implementation for export functionality
        $data = $this->getPerformanceMetrics($dateRange);
        
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($data);
            case 'pdf':
                return $this->exportToPdf($data);
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    public function getPerformanceChartData($dateRange = 7)
    {
        $startDate = now()->subDays($dateRange);
        $endDate = now();

        $data = Transaction::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(gross_sales) as total_sales')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'labels' => $data->pluck('date')->toArray(),
            'transaction_counts' => $data->pluck('count')->toArray(),
            'sales_totals' => $data->pluck('total_sales')->toArray(),
        ];
    }

    private function exportToCsv($data)
    {
        // Simple CSV export implementation
        $filename = 'performance_report_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Metric', 'Value']);
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    fputcsv($file, [$key, json_encode($value)]);
                } else {
                    fputcsv($file, [$key, $value]);
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function exportToPdf($data)
    {
        // For now, return CSV as PDF export requires additional dependencies
        return $this->exportToCsv($data);
    }

    private function calculateSuccessRate($startDate)
    {
        $total = Transaction::where('created_at', '>=', $startDate)->count();
        if ($total === 0) return 0;

        $successful = Transaction::where('created_at', '>=', $startDate)
            ->where('validation_status', 'VALID')
            ->where('job_status', 'COMPLETED')
            ->count();

        return round(($successful / $total) * 100, 2);
    }

    private function calculateAvgProcessingTime($startDate)
    {
        $avgTime = Transaction::where('created_at', '>=', $startDate)
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_time')
            ->value('avg_time');

        return $avgTime ? round($avgTime, 2) : 0;
    }

    private function calculateErrorRate($startDate)
    {
        $total = Transaction::where('created_at', '>=', $startDate)->count();
        if ($total === 0) return 0;

        $errors = Transaction::where('created_at', '>=', $startDate)
            ->where(function($query) {
                $query->where('validation_status', 'INVALID')
                      ->orWhere('job_status', 'FAILED');
            })
            ->count();

        return round(($errors / $total) * 100, 2);
    }

    private function getProviderStats($startDate)
    {
        return \App\Models\PosProvider::withCount(['terminals' => function($query) use ($startDate) {
            $query->whereHas('transactions', function($q) use ($startDate) {
                $q->where('created_at', '>=', $startDate);
            });
        }])->get()->map(function($provider) {
            return [
                'name' => $provider->name,
                'transaction_count' => $provider->terminals_count,
            ];
        });
    }

    private function resolveStart(?string $date, Carbon $fallback): Carbon
    {
        if (!$date) {
            return $fallback->copy()->startOfDay();
        }

        try {
            return Carbon::parse($date)->startOfDay();
        } catch (\Throwable $e) {
            return $fallback->copy()->startOfDay();
        }
    }

    private function resolveEnd(?string $date, Carbon $fallback): Carbon
    {
        if (!$date) {
            return $fallback->copy()->endOfDay();
        }

        try {
            return Carbon::parse($date)->endOfDay();
        } catch (\Throwable $e) {
            return $fallback->copy()->endOfDay();
        }
    }

    private function percentDelta(float|int $current, float|int $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100.0 : 0.0;
        }

        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);
    }

    private function buildSparkline(int $days, string $mode, string $column, Carbon $endDate, string $dateColumn): array
    {
        $column = $mode === 'sum' ? $this->transactionAmountColumn() : $column;
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $endDate->copy()->subDays($i);
            $query = Transaction::whereDate($dateColumn, $date->toDateString());

            if ($mode === 'count') {
                $values[] = (int) $query->count();
            } else {
                $values[] = (float) $query->selectRaw("COALESCE(SUM($column), 0) as value")->value('value');
            }
        }

        return $values;
    }

    private function transactionDateColumn(): string
    {
        if (Schema::hasColumn('transactions', 'transaction_timestamp')) {
            return 'transaction_timestamp';
        }

        if (Schema::hasColumn('transactions', 'processed_at')) {
            return 'processed_at';
        }

        return 'created_at';
    }

    private function transactionAmountColumn(): string
    {
        if (Schema::hasColumn('transactions', 'gross_sales')) {
            return 'gross_sales';
        }

        if (Schema::hasColumn('transactions', 'net_sales')) {
            return 'net_sales';
        }

        if (Schema::hasColumn('transactions', 'base_amount')) {
            return 'base_amount';
        }

        return 'id';
    }

    private function queueBacklog(): int
    {
        try {
            if (Schema::hasTable('jobs')) {
                return (int) DB::table('jobs')->count();
            }
        } catch (\Throwable $e) {
            // Graceful fallback
        }

        return 0;
    }

    private function memoryLimitToMb($memoryLimit): int
    {
        if (!$memoryLimit || $memoryLimit === '-1') {
            return 0;
        }

        $value = (int) $memoryLimit;
        $unit = strtolower(substr($memoryLimit, -1));

        return match ($unit) {
            'g' => $value * 1024,
            'k' => (int) round($value / 1024),
            'm' => $value,
            default => (int) round($value / 1024 / 1024),
        };
    }
}
