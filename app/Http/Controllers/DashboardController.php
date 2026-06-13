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
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;

class DashboardController extends Controller
{
    /**
     * Dismiss or mark admin notification as read
     */
    public function dismissNotification(Request $request)
    {
        $id = $request->input('id');
        if (!$id) {
            return response()->json(['error' => 'Notification ID required'], 400);
        }
        // Mark as read (add 'read_at' column if not exists)
        $updated = \DB::table('notifications')
            ->where('id', $id)
            ->update(['read_at' => now()]);
        return response()->json(['success' => $updated > 0]);
    }
    protected $dashboardService;

    public function __construct(\App\Services\DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index(Request $request)
    {
        $dateBasis = $request->input('date_basis', 'created'); // 'created' or 'transaction'
        $metrics = $this->getMetrics($dateBasis);
        $tenants = Tenant::count();
        $recentTransactions = $this->getRecentTransactions();
        $dateColumn = $dateBasis === 'transaction' ? 'transaction_timestamp' : 'created_at';
        $recentTransactionCount = Transaction::where($dateColumn, '>=', now()->subDays(7))->count();
        $errorCount = \App\Models\TransactionJob::where('created_at', '>=', now()->subDays(7))
            ->where('job_status', 'FAILED')
            ->count();
        $auditLogs = \App\Models\AuditLog::with('user')->orderByDesc('created_at')->limit(50)->get();

        // Surface recent admin notifications for excessive failed transactions
        $adminNotifications = [];
        if (auth()->check() && auth()->user()->hasRole('admin')) {
            $adminNotifications = \DB::table('notifications')
                ->where('type', 'App\\Notifications\\TransactionFailureThresholdExceeded')
                ->whereNull('read_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
        }

        return view('dashboard', compact(
            'metrics',
            'tenants',
            'recentTransactions',
            'recentTransactionCount',
            'errorCount',
            'auditLogs',
            'adminNotifications'
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

    protected function getMetrics($dateBasis = 'created')
    {
        // Get the ID for the 'active' status from the lookup table
        $activeStatusId = \App\Models\TerminalStatus::where('name', 'active')->value('id');
        $activeTerminalCount = $activeStatusId
            ? PosTerminal::where('status_id', $activeStatusId)->count()
            : 0;

        return [
            'today_count' => $this->getTransactionMetrics($dateBasis),
            'success_rate' => $this->getSuccessRate(),
            'avg_processing_time' => $this->getAvgProcessingTime(),
            'error_rate' => $this->getErrorRate(),
            'active_terminals' => $activeTerminalCount
        ];
    }

    // API: GET /api/dashboard/metrics
    public function apiMetrics(Request $request)
    {
        try {
            $dateKey = Carbon::today()->toDateString();
            $cacheKey = "dashboard.api_metrics.{$dateKey}";

            $metrics = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($request) {
                return $this->dashboardService->getAdvancedMetrics($request->all());
            });

            return response()->json($metrics);
        } catch (\Throwable $e) {
            \Log::error('Dashboard metrics endpoint failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'total_sales' => ['current' => 0, 'trend' => 0, 'sparkline' => []],
                'total_transactions' => ['current' => 0, 'trend' => 0, 'sparkline' => []],
                'voided_transactions' => ['current' => 0, 'trend' => 0],
                'void_rate' => ['current' => 0, 'trend' => 0],
                'active_terminals' => ['current' => 0, 'total' => 0],
                'reconciliation' => ['reconciled' => 0, 'total' => 0, 'pending' => 0, 'failed' => 0, 'trend' => 0],
                'pending_uploads' => ['current' => 0],
                'generated_at' => now()->toIso8601String(),
            ], 200);
        }
    }

    // API: GET /api/dashboard/charts
    public function apiCharts(Request $request)
    {
        $days = (int) $request->input('days', 7);
        $dateKey = Carbon::today()->toDateString();
        $cacheKey = "dashboard.api_charts.{$days}.{$dateKey}";

        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($days) {
            $labels = [];
            $salesData = [];
            $volumeData = [];
            $prevSalesData = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = Carbon::today()->subDays($i);
                $labels[] = $date->format('M d');

                $stats = Transaction::whereDate('transaction_timestamp', $date)
                    ->selectRaw('SUM(gross_sales) as sales, COUNT(*) as count')
                    ->first();

                $salesData[] = (float) ($stats->sales ?? 0);
                $volumeData[] = (int) ($stats->count ?? 0);

                // Previous period comparison (e.g., last week)
                $prevDate = $date->copy()->subDays($days);
                $prevStats = Transaction::whereDate('transaction_timestamp', $prevDate)
                    ->selectRaw('SUM(gross_sales) as sales')
                    ->first();
                $prevSalesData[] = (float) ($prevStats->sales ?? 0);
            }

            return [
                'labels' => $labels,
                'sales' => $salesData,
                'volume' => $volumeData,
                'previous_sales' => $prevSalesData,
            ];
        });

        return response()->json($data);
    }

    // API: GET /api/dashboard/system-health
    public function apiSystemHealth()
    {
        try {
            return response()->json($this->dashboardService->getSystemHealth());
        } catch (\Throwable $e) {
            \Log::error('Dashboard system-health endpoint failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'cpu' => 0,
                'memory' => 0,
                'network' => 'Unknown',
                'queues' => ['backlog' => 0],
            ], 200);
        }
    }

    // API: GET /api/dashboard/terminal-performance
    public function apiTerminalPerformance()
    {
        try {
            return response()->json($this->dashboardService->getTerminalPerformance());
        } catch (\Throwable $e) {
            \Log::error('Dashboard terminal-performance endpoint failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([], 200);
        }
    }

    // API: GET /api/dashboard/notifications
    public function apiNotifications(Request $request, NotificationService $notificationService)
    {
        $limit = (int) $request->input('limit', 10);
        $limit = max(1, min($limit, 50));

        $userId = Auth::id();
        $rows = $notificationService->getRecentNotifications($limit, $userId);

        $notifications = array_map(function ($row) {
            $data = null;
            if (isset($row->data)) {
                try {
                    $decoded = json_decode($row->data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $data = $decoded;
                    }
                } catch (\Throwable $e) {
                    $data = null;
                }
            }

            return [
                'id' => $row->id,
                'type' => $row->type ?? null,
                'data' => $data,
                'created_at' => $row->created_at ?? null,
                'read_at' => $row->read_at ?? null,
            ];
        }, $rows);

        return response()->json(['data' => $notifications]);
    }

    // API: POST /api/dashboard/notifications/dismiss
    public function apiDismissNotification(Request $request, NotificationService $notificationService)
    {
        $id = (string) $request->input('id');
        if ($id === '') {
            return response()->json(['error' => 'Notification ID required'], 400);
        }

        $success = $notificationService->markAsRead($id);

        return response()->json(['success' => $success]);
    }

    // API: GET /api/dashboard/transactions
    public function apiTransactions(Request $request)
    {
        $query = Transaction::with(['terminal', 'tenant', 'adjustments', 'taxes'])
            ->withSum('adjustments as adjustments_sum', 'amount');

        // Apply filters
        if ($request->filled('start_date')) {
            $query->whereDate('transaction_timestamp', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('transaction_timestamp', '<=', $request->input('end_date'));
        }
        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($t) use ($search) {
                        $t->where('trade_name', 'like', "%{$search}%");
                    });
            });
        }

        $query->where('validation_status', '!=', Transaction::VALIDATION_STATUS_PENDING);

        $transactions = $query->orderByDesc('transaction_timestamp')->paginate(50);
        return new \App\Http\Resources\TransactionCollection($transactions);
    }

    // API: GET /api/dashboard/export-transactions
    public function exportTransactions(Request $request)
    {
        $query = Transaction::with(['terminal', 'tenant']);

        if ($request->filled('start_date')) {
            $query->whereDate('transaction_timestamp', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('transaction_timestamp', '<=', $request->input('end_date'));
        }

        $transactions = $query->orderByDesc('transaction_timestamp')->limit(1000)->get();

        $filename = 'transactions_export_' . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Transaction ID', 'Tenant', 'Terminal', 'Net Sales', 'Timestamp']);

            foreach ($transactions as $tx) {
                fputcsv($file, [
                    $tx->id,
                    $tx->transaction_id,
                    $tx->tenant->trade_name ?? 'N/A',
                    $tx->terminal->terminal_uid ?? 'N/A',
                    $tx->net_sales,
                    $tx->transaction_timestamp
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
        $transactions = Transaction::with(['terminal', 'tenant.company'])
            ->withSum('adjustments as adjustments_sum', 'amount')
            ->select(['*']) // Ensure all columns are selected
            ->latest()
            ->take(10)
            ->get();

        return $transactions;
    }

    protected function getTransactionMetrics($dateBasis = 'created')
    {
        $dateColumn = $dateBasis === 'transaction' ? 'transaction_timestamp' : 'created_at';
        return Transaction::whereDate($dateColumn, Carbon::today())->count();
    }

    protected function getSuccessRate()
    {
        $total = Transaction::count();
        if ($total === 0)
            return 0;
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
        if ($total === 0)
            return 0;
        // Use TransactionJob for error count in normalized schema
        $errors = \App\Models\TransactionJob::where('job_status', 'FAILED')->count();
        return round(($errors / $total) * 100, 2);
    }

    // API: GET /api/dashboard/audit-logs
    public function apiAuditLogs(Request $request)
    {
        $query = \App\Models\AuditLog::with('user');

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->input('action') . '%');
        }

        $auditLogs = $query->orderByDesc('created_at')->paginate(50);

        return response()->json([
            'data' => $auditLogs->items(),
            'current_page' => $auditLogs->currentPage(),
            'last_page' => $auditLogs->lastPage(),
            'per_page' => $auditLogs->perPage(),
            'total' => $auditLogs->total(),
        ]);
    }

    // API: GET /api/dashboard/export-audit-logs
    public function exportAuditLogs(Request $request)
    {
        $query = \App\Models\AuditLog::with('user');

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->input('end_date'));
        }

        $logs = $query->orderByDesc('created_at')->limit(1000)->get();

        $filename = 'audit_logs_export_' . now()->format('Y-m-d') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'User', 'Action', 'Details', 'Timestamp']);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->user->name ?? 'System',
                    $log->action,
                    json_encode($log->details),
                    $log->created_at
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}