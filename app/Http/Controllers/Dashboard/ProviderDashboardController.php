<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProviderDashboardController extends Controller
{
    /**
     * Show the provider overview dashboard
     */
    public function index(Request $request)
    {
        $providers = PosProvider::withCount(['terminals as total_terminals'])
            ->withCount(['terminals as active_terminals' => function($query) {
                $query->where('status', 'active');
            }])
            ->get()
            ->map(function($provider) {
                // Calculate growth rate
                $oldestDate = Carbon::now()->subDays(30);
                $newTerminals = $provider->terminals()
                    ->where('enrolled_at', '>=', $oldestDate)
                    ->count();
                
                $growthRate = $provider->total_terminals > 0 
                    ? ($newTerminals / $provider->total_terminals) * 100 
                    : 0;
                
                $provider->growth_rate = round($growthRate, 2);
                return $provider;
            });
            
        // Get recent terminal enrollments
        $recentTerminals = PosTerminal::with(['provider:id,name', 'tenant:id,name'])
            ->latest('enrolled_at')
            ->take(10)
            ->get();
            
        return view('dashboard.providers.index', [
            'providers' => $providers,
            'recentTerminals' => $recentTerminals
        ]);
    }
    
    /**
     * Show detailed provider information
     */
    public function show(Request $request, $id)
    {
        $provider = PosProvider::with(['terminals' => function($query) {
            $query->latest('enrolled_at');
        }])->findOrFail($id);
        
        // Get historical statistics
        $statistics = ProviderStatistics::where('provider_id', $id)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();
            
        // Get terminals grouped by tenant
        $terminalsByTenant = PosTerminal::where('provider_id', $id)
            ->selectRaw('tenant_id, count(*) as terminal_count')
            ->with('tenant:id,name')
            ->groupBy('tenant_id')
            ->get();
            
        return view('dashboard.providers.show', [
            'provider' => $provider,
            'statistics' => $statistics,
            'terminalsByTenant' => $terminalsByTenant
        ]);
    }
}
