<?php

namespace App\Http\Controllers;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProvidersController extends Controller
{
    /**
     * Display a listing of the providers.
     */
    public function index()
    {
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
            ->take(10)
            ->get();
        
        return view('dashboard.providers.index', [
            'providers' => $providers,
            'recentTerminals' => $recentTerminals
        ]);
    }

    /**
     * Display the specified provider.
     */
    public function show($id)
    {
        $provider = PosProvider::findOrFail($id);
        
        // Load the terminals relationship for counting in the template
        $provider->load('terminals');
        
        // Calculate growth rate (last 30 days)
        $oldestDate = Carbon::now()->subDays(30);
        $newTerminals = $provider->terminals()
            ->where('enrolled_at', '>=', $oldestDate)
            ->count();
        
        $provider->growth_rate = $provider->terminals->count() > 0 
            ? ($newTerminals / $provider->terminals->count()) * 100 
            : 0;
        
        // Get provider statistics
        $stats = ProviderStatistics::where('provider_id', $id)
            ->orderBy('date', 'desc')
            ->take(30)
            ->get()
            ->reverse(); // Reverse to get chronological order for charts
        
        // Get terminal distribution by tenant
        $terminalsByTenant = PosTerminal::where('provider_id', $id)
            ->join('tenants', 'pos_terminals.tenant_id', '=', 'tenants.id')
            ->select('tenants.name', DB::raw('count(*) as total'))
            ->groupBy('tenants.name')
            ->orderBy('total', 'desc')
            ->get();
        
        // Get terminal status distribution
        $terminalsByStatus = PosTerminal::where('provider_id', $id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get();
        
        // Get latest terminals
        $latestTerminals = PosTerminal::with('tenant')
            ->where('provider_id', $id)
            ->latest('enrolled_at')
            ->take(10)
            ->get();
        
        return view('dashboard.providers.show', [
            'provider' => $provider,
            'stats' => $stats,
            'terminalsByTenant' => $terminalsByTenant,
            'terminalsByStatus' => $terminalsByStatus,
            'latestTerminals' => $latestTerminals,
            'chartLabels' => $stats->pluck('date')->map(function($date) {
                return Carbon::parse($date)->format('M d');
            })->toJson(),
            'chartData' => [
                'terminalCount' => $stats->pluck('terminal_count')->toJson(),
                'activeCount' => $stats->pluck('active_terminal_count')->toJson(),
                'newEnrollments' => $stats->pluck('new_enrollments')->toJson(),
            ]
        ]);
    }
}
