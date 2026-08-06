<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\TerminalStatus;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Build dashboard metrics payload used by /api/dashboard/metrics.
     */
    public function getAdvancedMetrics(array $filters = [], ?int $tenantId = null): array
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

        $currentGrossSales = (float) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd])
            ->selectRaw("COALESCE(SUM(gross_sales), 0) as value")
            ->value('value');

        $previousGrossSales = (float) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$previousStart, $previousEnd])
            ->selectRaw("COALESCE(SUM(gross_sales), 0) as value")
            ->value('value');

        $currentNetSales = (float) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd])
            ->selectRaw("COALESCE(SUM(net_sales), 0) as value")
            ->value('value');

        $previousNetSales = (float) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$previousStart, $previousEnd])
            ->selectRaw("COALESCE(SUM(net_sales), 0) as value")
            ->value('value');

        $currentTransactions = (int) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd])->count();
        $previousTransactions = (int) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$previousStart, $previousEnd])->count();

        $currentVoids = 0;
        $previousVoids = 0;
        if (Schema::hasColumn('transactions', 'voided_at')) {
            $currentVoids = (int) $this->transactionQuery($tenantId)->whereBetween('voided_at', [$todayStart, $todayEnd])->count();
            $previousVoids = (int) $this->transactionQuery($tenantId)->whereBetween('voided_at', [$previousStart, $previousEnd])->count();
        }

        $currentVoidRate = $currentTransactions > 0 ? round(($currentVoids / $currentTransactions) * 100, 1) : 0.0;
        $previousVoidRate = $previousTransactions > 0 ? round(($previousVoids / $previousTransactions) * 100, 1) : 0.0;

        $activeStatusId = TerminalStatus::where('name', 'active')->value('id');
        $activeTerminals = $activeStatusId
            ? (int) $this->terminalQuery($tenantId)->where('status_id', $activeStatusId)->count()
            : 0;
        $totalTerminals = (int) $this->terminalQuery($tenantId)->count();

        $activeTenants = 0;
        $totalTenants = 0;
        try {
            $activeTenants = DB::table('transactions')
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->whereBetween($dateColumn, [$todayStart, $todayEnd])
                ->distinct('tenant_id')
                ->count('tenant_id');
            $totalTenants = $tenantId !== null
                ? (int) Tenant::withoutGlobalScopes()->whereKey($tenantId)->count()
                : (int) Tenant::withoutGlobalScopes()->count();
        } catch (\Throwable $e) {
            \Log::warning('DashboardService: failed to compute active tenants', ['error' => $e->getMessage()]);
        }

        $reconciledQuery = $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd]);
        if ($hasValidationStatus) {
            $reconciledQuery->where('validation_status', Transaction::VALIDATION_STATUS_VALID);
        }
        if ($hasCompletedAt) {
            $reconciledQuery->whereNotNull('completed_at');
        }
        $reconciled = (int) $reconciledQuery->count();

        $pendingQuery = $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd]);
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

        $failedQuery = $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd]);
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

        // Missing terminal uploads
        $missingUploads = 0;
        try {
            $missingUploads = (int) $this->intakeQuery($tenantId)
                ->where('processing_status', TransactionIntake::PROCESSING_STATUS_PROCESSED)
                ->whereBetween('received_at', [$todayStart, $todayEnd])
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('transactions')
                        ->whereColumn('transactions.submission_uuid', 'transaction_intake.submission_uuid');
                })
                ->count();
        } catch (\Throwable $e) {
            \Log::warning('DashboardService: failed to compute missing uploads', ['error' => $e->getMessage()]);
        }

        // Invalid tax records
        $invalidTaxRecords = 0;
        try {
            $invalidTaxRecords = (int) $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd])
                ->where(function($query) {
                    $query->where(function($q) {
                        $q->whereIn('validation_status', ['FAILED', 'INVALID', 'ERROR'])
                          ->where(function($sub) {
                              $sub->where('last_error', 'LIKE', '%tax%')
                                  ->orWhere('last_error', 'LIKE', '%vat%');
                          });
                    })
                    ->orWhere(function($q) {
                        $q->where('tax_exempt', false)
                          ->where('vatable_sales', '>', 0)
                          ->whereRaw('ABS(vatable_sales * 0.12 - vat_amount) > 0.02');
                    });
                })
                ->count();
        } catch (\Throwable $e) {
            \Log::warning('DashboardService: failed to compute invalid tax records', ['error' => $e->getMessage()]);
        }

        $totalExceptions = $failed + $missingUploads + $invalidTaxRecords;

        // Compliance status
        $csmrReady = ($failed === 0 && $pending === 0 && $currentTransactions > 0);
        $birExportGenerated = ($currentTransactions > 0 && $failed === 0);
        $taxValidationPassed = ($invalidTaxRecords === 0 && $currentTransactions > 0);

        // Top Tenants ranking
        $topTenants = [];
        try {
            $topTenantsData = DB::table('transactions as tr')
                ->join('tenants as t', 't.id', '=', 'tr.tenant_id')
                ->when($tenantId !== null, fn ($query) => $query->where('tr.tenant_id', $tenantId))
                ->whereBetween("tr.$dateColumn", [$todayStart, $todayEnd])
                ->where('tr.validation_status', 'VALID')
                ->selectRaw("t.trade_name, COALESCE(SUM(tr.gross_sales), 0) as total_revenue")
                ->groupBy('t.trade_name')
                ->orderByDesc('total_revenue')
                ->limit(5)
                ->get();
            $topTenants = $topTenantsData->map(function ($row) {
                return [
                    'trade_name' => $row->trade_name,
                    'total_revenue' => (float) $row->total_revenue,
                ];
            })->all();
        } catch (\Throwable $e) {
            \Log::warning('DashboardService: failed to fetch top tenants', ['error' => $e->getMessage()]);
        }

        // Revenue Composition
        $composition = [
            'net_sales' => 0.0,
            'tax_exempt' => 0.0,
            'vat' => 0.0,
            'refunds' => 0.0,
            'discounts' => 0.0,
        ];
        try {
            $compData = $this->transactionQuery($tenantId)->whereBetween($dateColumn, [$todayStart, $todayEnd])
                ->where('validation_status', 'VALID')
                ->selectRaw("
                    COALESCE(SUM(net_sales), 0) as net_sales,
                    COALESCE(SUM(sc_vat_exempt_sales), 0) as tax_exempt,
                    COALESCE(SUM(vat_amount), 0) as vat,
                    COALESCE(SUM(refund_amount), 0) as refunds,
                    COALESCE(SUM(promo_discount + senior_discount + pwd_discount + discount_total), 0) as discounts
                ")
                ->first();
            if ($compData) {
                $composition = [
                    'net_sales' => round((float) $compData->net_sales, 2),
                    'tax_exempt' => round((float) $compData->tax_exempt, 2),
                    'vat' => round((float) $compData->vat, 2),
                    'refunds' => round((float) $compData->refunds, 2),
                    'discounts' => round((float) $compData->discounts, 2),
                ];
            }
        } catch (\Throwable $e) {
            \Log::warning('DashboardService: failed to compute revenue composition', ['error' => $e->getMessage()]);
        }

        return [
            'total_sales' => [
                'current' => round($currentGrossSales, 2),
                'trend' => $this->percentDelta($currentGrossSales, $previousGrossSales),
                'sparkline' => $this->buildSparkline($rangeDays, 'sum', 'gross_sales', $todayEnd, $dateColumn, $tenantId),
            ],
            'total_net_sales' => [
                'current' => round($currentNetSales, 2),
                'trend' => $this->percentDelta($currentNetSales, $previousNetSales),
            ],
            'total_transactions' => [
                'current' => $currentTransactions,
                'trend' => $this->percentDelta($currentTransactions, $previousTransactions),
                'sparkline' => $this->buildSparkline($rangeDays, 'count', '*', $todayEnd, $dateColumn, $tenantId),
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
            'active_tenants' => [
                'current' => $activeTenants,
                'total' => $totalTenants,
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
            'exceptions' => [
                'failed_reconciliations' => $failed,
                'missing_uploads' => $missingUploads,
                'invalid_tax_records' => $invalidTaxRecords,
                'total_exceptions' => $totalExceptions,
            ],
            'compliance' => [
                'csmr_ready' => $csmrReady,
                'bir_export_generated' => $birExportGenerated,
                'tax_validation_passed' => $taxValidationPassed,
            ],
            'top_tenants' => $topTenants,
            'revenue_composition' => $composition,
            'sync_status' => [
                'last_sync' => $now->format('g:i:s A'),
                'records_synced' => $currentTransactions,
                'status' => 'Healthy',
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

        if ($rows->isEmpty()) {
            $rows = DB::table('pos_terminals as pt')
                ->leftJoin('tenants as t', 't.id', '=', 'pt.tenant_id')
                ->selectRaw('pt.id as terminal_id, pt.serial_number, COALESCE(t.trade_name, ?) as trade_name, 0 as total_sales', ['Unknown Tenant'])
                ->orderBy('pt.serial_number')
                ->limit(20)
                ->get();
        }

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

    private function buildSparkline(int $days, string $mode, string $column, Carbon $endDate, string $dateColumn, ?int $tenantId): array
    {
        $column = $mode === 'sum' ? $this->transactionAmountColumn() : $column;
        $values = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $endDate->copy()->subDays($i);
            $query = $this->transactionQuery($tenantId)->whereDate($dateColumn, $date->toDateString());

            if ($mode === 'count') {
                $values[] = (int) $query->count();
            } else {
                $values[] = (float) $query->selectRaw("COALESCE(SUM($column), 0) as value")->value('value');
            }
        }

        return $values;
    }

    private function transactionQuery(?int $tenantId)
    {
        return Transaction::withoutGlobalScopes()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function terminalQuery(?int $tenantId)
    {
        return PosTerminal::withoutGlobalScopes()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
    }

    private function intakeQuery(?int $tenantId)
    {
        return TransactionIntake::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
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
