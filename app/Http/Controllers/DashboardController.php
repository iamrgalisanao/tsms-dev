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
        $metrics = [
            'today_count' => $this->getTransactionMetrics(),
            'success_rate' => $this->getSuccessRate(),
            'avg_processing_time' => $this->getAvgProcessingTime(),
            'error_rate' => $this->getErrorRate()
        ];

        // Add terminal metrics
        $terminalCount = PosTerminal::count();
        $activeTerminalCount = PosTerminal::where('status', 'ACTIVE')->count();
        $recentTransactionCount = Transaction::where('created_at', '>=', now()->subDays(7))->count();
        $errorCount = Transaction::where('validation_status', 'ERROR')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // Add recent terminals
        $recentTerminals = PosTerminal::with(['provider', 'tenant'])
            ->orderBy('enrolled_at', 'desc')
            ->take(10)
            ->get();

        $recentTransactions = Transaction::with(['terminal', 'tenant'])
            ->latest()
            ->take(10)
            ->get();

        $providers = PosProvider::withCount(['terminals', 'activeTerminals'])->get();

        return view('dashboard', compact(
            'metrics',
            'terminalCount',
            'activeTerminalCount',
            'recentTransactionCount',
            'errorCount',
            'recentTransactions',
            'recentTerminals',
            'providers'
        ));
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