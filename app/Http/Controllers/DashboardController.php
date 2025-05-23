<?php

namespace App\Http\Controllers;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\IntegrationLog;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $metrics = $this->getMetrics();
        $enrollmentData = $this->getEnrollmentData();
        $providers = $this->getProviderStats();
        $recentTerminals = $this->getRecentTerminals();
        $recentTransactions = $this->getRecentTransactions();
        $recentTransactionCount = Transaction::where('created_at', '>=', now()->subDays(7))->count();
        $errorCount = Transaction::where('created_at', '>=', now()->subDays(7))
            ->where('validation_status', 'ERROR')
            ->count();

        return view('dashboard', compact(
            'metrics',
            'enrollmentData',
            'providers',
            'recentTerminals',
            'recentTransactions',
            'recentTransactionCount',
            'errorCount'
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

    protected function getMetrics()
    {
        $activeTerminalCount = PosTerminal::where('status', 'active')->count();
        
        return [
            'today_count' => $this->getTransactionMetrics(),
            'success_rate' => $this->getSuccessRate(),
            'avg_processing_time' => $this->getAvgProcessingTime(),
            'error_rate' => $this->getErrorRate(),
            'active_terminals' => $activeTerminalCount
        ];
    }

    protected function getEnrollmentData()
    {
        $dates = collect(range(30, 0))->map(function($days) {
            return now()->subDays($days)->format('Y-m-d');
        });

        $enrollments = PosTerminal::selectRaw('DATE(enrolled_at) as date, COUNT(*) as count')
            ->whereDate('enrolled_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        $activeTerminals = PosTerminal::selectRaw('DATE(enrolled_at) as date, COUNT(*) as count')
            ->where('status', 'active')
            ->whereDate('enrolled_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->pluck('count', 'date')
            ->toArray();

        return [
            'labels' => $dates->values(),
            'totalTerminals' => $dates->map(fn($date) => array_sum(
                array_filter($enrollments, fn($k) => $k <= $date, ARRAY_FILTER_USE_KEY)
            )),
            'activeTerminals' => $dates->map(fn($date) => $activeTerminals[$date] ?? 0),
            'newEnrollments' => $dates->map(fn($date) => $enrollments[$date] ?? 0)
        ];
    }

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
        return Transaction::with(['terminal', 'tenant'])
            ->latest()
            ->take(10)
            ->get();
    }

    protected function getTransactionMetrics()
    {
        return Transaction::whereDate('created_at', Carbon::today())->count();
    }

    protected function getSuccessRate()
    {
        $total = Transaction::count();
        if ($total === 0) return 0;
        
        $success = Transaction::where('validation_status', 'VALID')->count();
        return round(($success / $total) * 100, 2);
    }

    protected function getAvgProcessingTime()
    {
        return Transaction::whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, completed_at)) as avg_time')
            ->value('avg_time') ?? 0;
    }

    protected function getErrorRate()
    {
        $total = Transaction::count();
        if ($total === 0) return 0;
        
        $errors = Transaction::where('validation_status', 'ERROR')->count();
        return round(($errors / $total) * 100, 2);
    }
}