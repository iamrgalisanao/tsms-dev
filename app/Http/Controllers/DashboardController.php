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
        // Get summary data for dashboard
        $terminalCount = PosTerminal::count();
        $activeTerminalCount = PosTerminal::where('status', 'active')->count();
        $recentTransactionCount = Transaction::whereDate('created_at', '>=', Carbon::now()->subDays(7))->count();
        $errorCount = IntegrationLog::where('severity', 'error')
                                   ->whereDate('created_at', '>=', Carbon::now()->subDays(7))
                                   ->count();

        // Get providers with terminal counts and load growth rate calculation
        $providers = PosProvider::withCount(['terminals as total_terminals'])
            ->withCount(['terminals as active_terminals' => function($query) {
                $query->where('status', 'active');
            }])
            ->get()
            ->each(function($provider) {
                // Calculate growth rate (last 30 days)
                $oldestDate = Carbon::now()->subDays(30);
                $newTerminals = $provider->terminals()
                    ->where('enrolled_at', '>=', $oldestDate)
                    ->count();
                
                $growthRate = $provider->total_terminals > 0 
                    ? ($newTerminals / $provider->total_terminals) * 100 
                    : 0;
                
                $provider->growth_rate = round($growthRate, 1);
            });
        
        // Get most recently enrolled terminals
        $recentTerminals = PosTerminal::with(['provider', 'tenant'])
            ->whereNotNull('enrolled_at')
            ->latest('enrolled_at')
            ->take(5)
            ->get();

        return view('dashboard', [
            'terminalCount' => $terminalCount,
            'activeTerminalCount' => $activeTerminalCount,
            'recentTransactionCount' => $recentTransactionCount,
            'errorCount' => $errorCount,
            'providers' => $providers,
            'recentTerminals' => $recentTerminals
        ]);
    }
}