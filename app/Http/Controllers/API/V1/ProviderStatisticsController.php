<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ProviderStatisticsController extends Controller
{
    /**
     * Get an overview of all providers and their statistics
     */
    public function overview(Request $request)
    {
        // Get all providers with their terminal counts
        $providers = PosProvider::withCount(['terminals as total_terminals'])
            ->withCount(['terminals as active_terminals' => function($query) {
                $query->where('status', 'active');
            }])
            ->get()
            ->map(function($provider) {
                // Calculate growth rate (last 30 days)
                $oldestDate = Carbon::now()->subDays(30);
                $newTerminals = $provider->terminals()
                    ->where('enrolled_at', '>=', $oldestDate)
                    ->count();
                
                $growthRate = $provider->total_terminals > 0 
                    ? ($newTerminals / $provider->total_terminals) * 100 
                    : 0;
                
                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'code' => $provider->code,
                    'status' => $provider->status,
                    'total_terminals' => $provider->total_terminals,
                    'active_terminals' => $provider->active_terminals,
                    'growth_rate' => round($growthRate, 2)
                ];
            });
            
        // Calculate system-wide statistics
        $totalTerminals = PosTerminal::count();
        $activeTerminals = PosTerminal::where('status', 'active')->count();
        $newThisMonth = PosTerminal::where('enrolled_at', '>=', Carbon::now()->startOfMonth())->count();
        
        return response()->json([
            'providers' => $providers,
            'totals' => [
                'total_providers' => $providers->count(),
                'total_terminals' => $totalTerminals,
                'active_terminals' => $activeTerminals,
                'new_this_month' => $newThisMonth,
                'active_percentage' => $totalTerminals > 0 ? round(($activeTerminals / $totalTerminals) * 100, 2) : 0
            ]
        ]);
    }
    
    /**
     * Get detailed statistics for a specific provider
     */
    public function providerDetail(Request $request, $id)
    {
        $provider = PosProvider::with(['terminals' => function($query) {
            $query->orderBy('enrolled_at', 'desc');
        }])->findOrFail($id);
        
        // Get statistics over time
        $stats = ProviderStatistics::where('provider_id', $id)
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();
            
        // Get terminals per tenant
        $terminalsByTenant = PosTerminal::where('provider_id', $id)
            ->select('tenant_id', DB::raw('count(*) as terminal_count'))
            ->with('tenant:id,name')
            ->groupBy('tenant_id')
            ->get()
            ->map(function($item) {
                return [
                    'tenant_id' => $item->tenant_id,
                    'tenant_name' => $item->tenant->name ?? 'Unknown',
                    'terminal_count' => $item->terminal_count
                ];
            });
            
        return response()->json([
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'code' => $provider->code,
                'description' => $provider->description,
                'contact_email' => $provider->contact_email,
                'contact_phone' => $provider->contact_phone,
                'status' => $provider->status,
                'created_at' => $provider->created_at
            ],
            'summary' => [
                'total_terminals' => $provider->terminals->count(),
                'active_terminals' => $provider->terminals->where('status', 'active')->count(),
                'inactive_terminals' => $provider->terminals->where('status', '!=', 'active')->count(),
                'latest_enrollment' => $provider->terminals->first()?->enrolled_at,
                'growth_rate' => $provider->growth_rate
            ],
            'statistics' => $stats,
            'terminals_by_tenant' => $terminalsByTenant
        ]);
    }
    
    /**
     * Get growth statistics for all providers
     */
    public function growthStats(Request $request)
    {
        // Get enrollment trends for all providers over time
        $trends = DB::table('pos_terminals')
            ->join('pos_providers', 'pos_terminals.provider_id', '=', 'pos_providers.id')
            ->select(
                'pos_providers.name as provider_name',
                DB::raw('DATE(pos_terminals.enrolled_at) as enrollment_date'),
                DB::raw('count(*) as terminal_count')
            )
            ->whereNotNull('pos_terminals.enrolled_at')
            ->groupBy('pos_providers.name', 'enrollment_date')
            ->orderBy('enrollment_date')
            ->get();
            
        // Get monthly growth rates
        $sixMonthsAgo = Carbon::now()->subMonths(6);
        $monthlyGrowth = DB::table('pos_terminals')
            ->join('pos_providers', 'pos_terminals.provider_id', '=', 'pos_providers.id')
            ->select(
                'pos_providers.name as provider_name',
                DB::raw('YEAR(pos_terminals.enrolled_at) as year'),
                DB::raw('MONTH(pos_terminals.enrolled_at) as month'),
                DB::raw('count(*) as new_terminals')
            )
            ->where('pos_terminals.enrolled_at', '>=', $sixMonthsAgo)
            ->groupBy('pos_providers.name', 'year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
            
        return response()->json([
            'enrollment_trends' => $trends,
            'monthly_growth' => $monthlyGrowth
        ]);
    }
    
    /**
     * Get terminal list with provider information
     */
    public function terminals(Request $request)
    {
        $query = PosTerminal::with(['provider:id,name,code', 'tenant:id,name'])
            ->select(['id', 'provider_id', 'tenant_id', 'terminal_uid', 'status', 'enrolled_at']);
            
        // Apply filters
        if ($request->has('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('terminal_uid', 'like', "%{$search}%")
                  ->orWhere('machine_number', 'like', "%{$search}%");
            });
        }
        
        // Paginate the results
        $terminals = $query->latest('enrolled_at')->paginate($request->per_page ?? 15);
        
        return response()->json($terminals);
    }
}