<?php

namespace App\Http\Controllers;

use App\Models\PosProvider;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\IntegrationLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\TransactionJob;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(\App\Services\DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index()
    {
        $metrics = $this->getMetrics();
        $tenants= Tenant::count();
        // $enrollmentData = $this->getEnrollmentData();
        // $providers = $this->getProviderStats();
        // $recentTerminals = $this->getRecentTerminals();
        $recentTransactions = $this->getRecentTransactions();
        $recentTransactionCount = Transaction::where('created_at', '>=', now()->subDays(7))->count();
        // Use TransactionJob for error count in normalized schema
        $errorCount = \App\Models\TransactionJob::where('created_at', '>=', now()->subDays(7))
            ->where('job_status', 'FAILED')
            ->count();
        $auditLogs = \App\Models\AuditLog::with('user')->orderByDesc('created_at')->limit(50)->get();

        return view('dashboard', compact(
            'metrics',
            'tenants',
            // 'providers',
            'recentTransactions',
            'recentTransactionCount',
            'errorCount',
            'auditLogs'
        ));
    }

    public function performance()
    {
        try {
            $metrics = $this->dashboardService->getPerformanceMetrics();
            $chartData = $this->dashboardService->getPerformanceChartData();
            
            return view('dashboard.metrics.provider-performance', compact('metrics', 'chartData'));
        } catch (\Exception $e) {
            \Log::error('Performance dashboard error', ['error' => $e->getMessage()]);
            return redirect()->route('dashboard')->with('error', 'Error loading performance metrics');
        }
    }

    public function exportPerformance(Request $request)
    {
        try {
            $format = $request->input('format', 'csv');
            $dateRange = $request->input('dateRange', '7');
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            return $this->dashboardService->exportPerformanceReport($format, $dateRange, $startDate, $endDate);
        } catch (\Exception $e) {
            return back()->with('error', 'Error exporting performance report');
        }
    }

    // protected function getMetrics()
    // {
    //     $activeTerminalCount = PosTerminal::where('status', 'active')->count();
    //     return [
    //         'today_count' => $this->getTransactionMetrics(),
    //         'success_rate' => $this->getSuccessRate(),
    //         'avg_processing_time' => $this->getAvgProcessingTime(),
    //         'error_rate' => $this->getErrorRate(),
    //         'active_terminals' => $activeTerminalCount
    //     ];
    // }

    protected function getMetrics()
    {
        // Get the ID for the 'active' status from the lookup table
        $activeStatusId = \App\Models\TerminalStatus::where('name', 'active')->value('id');
        $activeTerminalCount = $activeStatusId
            ? PosTerminal::where('status_id', $activeStatusId)->count()
            : 0;

        return [
            'today_count' => $this->getTransactionMetrics(),
            'success_rate' => $this->getSuccessRate(),
            'avg_processing_time' => $this->getAvgProcessingTime(),
            'error_rate' => $this->getErrorRate(),
            'active_terminals' => $activeTerminalCount
        ];
    }

    // API: GET /api/dashboard/metrics
    public function apiMetrics(Request $request)
    {
        $today = Carbon::today();
    $totalSales = Transaction::whereDate('transaction_timestamp', $today)->sum('gross_sales');
        $totalTransactions = Transaction::whereDate('transaction_timestamp', $today)->count();
        // Count transactions voided today using 'voided_at' timestamp
        $voidedTransactions = Transaction::whereDate('voided_at', $today)->count();
        $activeTerminals = PosTerminal::where('is_active', true)->count();

        return response()->json([
            'total_sales' => $totalSales,
            'total_transactions' => $totalTransactions,
            'voided_transactions' => $voidedTransactions,
            'active_terminals' => $activeTerminals,
        ]);
    }

    // API: GET /api/dashboard/charts
    public function apiCharts(Request $request)
    {
        $days = 7;
        $labels = [];
        $salesData = [];
        $volumeData = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $labels[] = $date->format('Y-m-d');
            $salesData[] = Transaction::whereDate('transaction_timestamp', $date)->sum('gross_sales');
            $volumeData[] = Transaction::whereDate('transaction_timestamp', $date)->count();
        }
        return response()->json([
            'labels' => $labels,
            'sales' => $salesData,
            'volume' => $volumeData,
        ]);
    }

    // API: GET /api/dashboard/transactions
    public function apiTransactions(Request $request)
    {
        // include tenant/terminal relations and precompute adjustments sum for efficient rendering
        $query = Transaction::with(['terminal', 'tenant', 'adjustments', 'taxes'])
            ->withSum('adjustments as adjustments_sum', 'amount');
        if ($request->has('date')) {
            $query->whereDate('transaction_timestamp', $request->input('date'));
        }
        $transactions = $query->orderByDesc('transaction_timestamp')->paginate(50);
        return new \App\Http\Resources\TransactionCollection($transactions);
    }

    // API: POST /api/dashboard/forward-transaction/{id}
    public function forwardTransaction(Request $request, $transactionId)
    {
        try {
            $transaction = Transaction::with(['adjustments', 'taxes'])->findOrFail($transactionId);

            // Check if user has permission (optional - adjust as needed)
            // if (!auth()->user() || !auth()->user()->hasAnyRole(['admin', 'manager'])) {
            //     return response()->json(['error' => 'Forbidden'], 403);
            // }

            $forwardingService = app(\App\Services\WebAppForwardingService::class);
            $result = $forwardingService->forwardTransactionImmediately($transaction);

            if ($result['success']) {
                return response()->json([
                    'status' => 'success',
                    'message' => $result['message'],
                    'batch_id' => $result['batch_id'],
                    'transaction_id' => $transaction->transaction_id
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['error'],
                    'batch_id' => $result['batch_id'] ?? null,
                    'transaction_id' => $transaction->transaction_id
                ], 500);
            }

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found',
                'transaction_id' => $transactionId
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Transaction forwarding failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Forwarding failed: ' . $e->getMessage(),
                'transaction_id' => $transactionId
            ], 500);
        }
    }

    // protected function getEnrollmentData()
    // {
    //     $dates = collect(range(30, 0))->map(function($days) {
    //         return now()->subDays($days)->format('Y-m-d');
    //     });

    //     $enrollments = PosTerminal::selectRaw('DATE(enrolled_at) as date, COUNT(*) as count')
    //         ->whereDate('enrolled_at', '>=', now()->subDays(30))
    //         ->groupBy('date')
    //         ->pluck('count', 'date')
    //         ->toArray();

    //     $activeTerminals = PosTerminal::selectRaw('DATE(enrolled_at) as date, COUNT(*) as count')
    //         ->where('status', 'active')
    //         ->whereDate('enrolled_at', '>=', now()->subDays(30))
    //         ->groupBy('date')
    //         ->pluck('count', 'date')
    //         ->toArray();

    //     return [
    //         'labels' => $dates->values(),
    //         'totalTerminals' => $dates->map(fn($date) => array_sum(
    //             array_filter($enrollments, fn($k) => $k <= $date, ARRAY_FILTER_USE_KEY)
    //         )),
    //         'activeTerminals' => $dates->map(fn($date) => $activeTerminals[$date] ?? 0),
    //         'newEnrollments' => $dates->map(fn($date) => $enrollments[$date] ?? 0)
    //     ];
    // }

    protected function getProviderStats()
    {
        return PosProvider::withCount(['terminals', 'activeTerminals'])->get();
    }

    protected function getRecentTerminals()
    {
        return PosTerminal::with(['provider', 'tenant'])
            ->orderBy('enrolled_at', 'desc')
            ->take(10)
            ->get();
    }

    protected function getRecentTransactions()
    {
        $transactions = Transaction::with(['terminal', 'tenant'])
            ->withSum('adjustments as adjustments_sum', 'amount')
            ->select(['*']) // Ensure all columns are selected
            ->latest()
            ->take(10)
            ->get();

    return $transactions;
    }

    protected function getTransactionMetrics()
    {
        return Transaction::whereDate('created_at', Carbon::today())->count();
    }

    protected function getSuccessRate()
    {
        $total = Transaction::count();
        if ($total === 0) return 0;
        // Use TransactionJob for success count in normalized schema
        $success = \App\Models\TransactionJob::where('job_status', 'COMPLETED')->count();
        return round(($success / $total) * 100, 2);
    }

    protected function getAvgProcessingTime()
    {
        // Use TransactionJob for normalized schema
        return TransactionJob::whereNotNull('completed_at')
            ->where('job_status', 'COMPLETED')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_time')
            ->value('avg_time') ?? 0;
    }

    protected function getErrorRate()
    {
        $total = Transaction::count();
        if ($total === 0) return 0;
        // Use TransactionJob for error count in normalized schema
        $errors = \App\Models\TransactionJob::where('job_status', 'FAILED')->count();
        return round(($errors / $total) * 100, 2);
    }
}