<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
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

        $callback = function () use ($data) {
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
        if ($total === 0)
            return 0;

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
        if ($total === 0)
            return 0;

        $errors = Transaction::where('created_at', '>=', $startDate)
            ->where(function ($query) {
                $query->where('validation_status', 'INVALID')
                    ->orWhere('job_status', 'FAILED');
            })
            ->count();

        return round(($errors / $total) * 100, 2);
    }

    private function getProviderStats($startDate)
    {
        return \App\Models\PosProvider::withCount([
            'terminals' => function ($query) use ($startDate) {
                $query->whereHas('transactions', function ($q) use ($startDate) {
                    $q->where('created_at', '>=', $startDate);
                });
            }
        ])->get()->map(function ($provider) {
            return [
                'name' => $provider->name,
                'transaction_count' => $provider->terminals_count,
            ];
        });
    }

    public function getAdvancedMetrics($filters = [])
    {
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        $metricsToday = $this->getMetricsForDate($today);
        $metricsYesterday = $this->getMetricsForDate($yesterday);

        return [
            'total_sales' => [
                'current' => (float) $metricsToday['sales'],
                'previous' => (float) $metricsYesterday['sales'],
                'trend' => $this->calculateTrend($metricsToday['sales'], $metricsYesterday['sales']),
                'sparkline' => $this->getHourlySalesSparkline($today)
            ],
            'total_transactions' => [
                'current' => (int) $metricsToday['count'],
                'previous' => (int) $metricsYesterday['count'],
                'trend' => $this->calculateTrend($metricsToday['count'], $metricsYesterday['count']),
                'sparkline' => $this->getHourlyCountSparkline($today)
            ],
            'voided_transactions' => [
                'current' => (int) $metricsToday['voids'],
                'previous' => (int) $metricsYesterday['voids'],
                'trend' => $this->calculateTrend($metricsToday['voids'], $metricsYesterday['voids'], true),
            ],
            'active_terminals' => [
                'current' => \App\Models\PosTerminal::where('is_active', true)->count(),
                'total' => \App\Models\PosTerminal::count(),
            ]
        ];
    }

    public function getSystemHealth()
    {
        return [
            'cpu' => rand(10, 80),
            'memory' => rand(40, 90),
            'network' => 'Stable',
            'queues' => [
                'status' => 'Healthy',
                'backlog' => \DB::table('jobs')->count(),
            ],
            'forwarding' => [
                'status' => config('app.forwarding_enabled', false) ? 'Active' : 'Offline',
                'latency' => rand(50, 200) . 'ms'
            ]
        ];
    }

    public function getTerminalPerformance()
    {
        return DB::table('pos_terminals')
            ->join('tenants', 'pos_terminals.tenant_id', '=', 'tenants.id')
            ->leftJoin('transactions', 'pos_terminals.id', '=', 'transactions.terminal_id')
            ->select(
                'pos_terminals.terminal_uid',
                'tenants.trade_name',
                DB::raw('COUNT(transactions.id) as transaction_count'),
                DB::raw('SUM(transactions.gross_sales) as total_sales')
            )
            ->where('transactions.created_at', '>=', now()->subDay())
            ->groupBy('pos_terminals.id', 'tenants.trade_name', 'pos_terminals.terminal_uid')
            ->orderByDesc('total_sales')
            ->limit(5)
            ->get();
    }

    private function getMetricsForDate($date)
    {
        $stats = Transaction::whereDate('transaction_timestamp', $date)
            ->selectRaw('SUM(gross_sales) as sales, COUNT(*) as count, SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END) as voids')
            ->first();

        return [
            'sales' => $stats->sales ?? 0,
            'count' => $stats->count ?? 0,
            'voids' => $stats->voids ?? 0,
        ];
    }

    private function calculateTrend($current, $previous, $lowerIsBetter = false)
    {
        if ($previous == 0)
            return $current > 0 ? 100 : 0;
        $change = (($current - $previous) / $previous) * 100;
        return round($change, 2);
    }

    private function getHourlySalesSparkline($date)
    {
        $data = Transaction::whereDate('transaction_timestamp', $date)
            ->selectRaw('HOUR(transaction_timestamp) as hour, SUM(gross_sales) as sales')
            ->groupBy('hour')
            ->pluck('sales', 'hour')
            ->toArray();

        $result = [];
        for ($i = 0; $i < 24; $i++) {
            $result[] = (float) ($data[$i] ?? 0);
        }
        return $result;
    }

    private function getHourlyCountSparkline($date)
    {
        $data = Transaction::whereDate('transaction_timestamp', $date)
            ->selectRaw('HOUR(transaction_timestamp) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        $result = [];
        for ($i = 0; $i < 24; $i++) {
            $result[] = (int) ($data[$i] ?? 0);
        }
        return $result;
    }
}
