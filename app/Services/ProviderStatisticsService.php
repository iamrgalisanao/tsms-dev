<?php

namespace App\Services;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProviderStatisticsService
{
    /**
     * Get current total terminals by provider
     */
    public function getTotalTerminalsByProvider()
    {
        return PosProvider::withCount('terminals')
            ->orderByDesc('terminals_count')
            ->get();
    }
    
    /**
     * Get active vs inactive terminals by provider
     */
    public function getTerminalStatusByProvider()
    {
        $providers = PosProvider::all();
        
        $result = [];
        foreach ($providers as $provider) {
            $result[] = [
                'provider_name' => $provider->name,
                'provider_code' => $provider->code,
                'active_count' => $provider->active_terminals_count,
                'inactive_count' => $provider->inactive_terminals_count,
                'total_count' => $provider->total_terminals_count
            ];
        }
        
        return $result;
    }
    
    /**
     * Get enrollment trend over time (daily for last 30 days)
     */
    public function getEnrollmentTrend($days = 30)
    {
        $startDate = Carbon::now()->subDays($days);
        
        return ProviderStatistics::select('date', 
                DB::raw('SUM(new_enrollments) as total_enrollments'))
            ->where('date', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
    
    /**
     * Get provider enrollment trend (daily for last 30 days)
     */
    public function getProviderEnrollmentTrend($providerId, $days = 30)
    {
        $startDate = Carbon::now()->subDays($days);
        
        return ProviderStatistics::where('provider_id', $providerId)
            ->where('date', '>=', $startDate)
            ->orderBy('date')
            ->get();
    }
    
    /**
     * Get top providers by terminal count
     */
    public function getTopProviders($limit = 5)
    {
        return PosProvider::withCount('terminals')
            ->orderByDesc('terminals_count')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Get provider growth rate (last 30 days vs previous 30 days)
     */
    public function getProviderGrowthRate()
    {
        $currentPeriodStart = Carbon::now()->subDays(30);
        $previousPeriodStart = Carbon::now()->subDays(60);
        
        $providers = PosProvider::all();
        $result = [];
        
        foreach ($providers as $provider) {
            // Current period enrollments
            $currentPeriodEnrollments = PosTerminal::where('provider_id', $provider->id)
                ->where('enrolled_at', '>=', $currentPeriodStart)
                ->count();
                
            // Previous period enrollments
            $previousPeriodEnrollments = PosTerminal::where('provider_id', $provider->id)
                ->where('enrolled_at', '>=', $previousPeriodStart)
                ->where('enrolled_at', '<', $currentPeriodStart)
                ->count();
                
            // Calculate growth rate
            $growthRate = 0;
            if ($previousPeriodEnrollments > 0) {
                $growthRate = (($currentPeriodEnrollments - $previousPeriodEnrollments) / $previousPeriodEnrollments) * 100;
            } elseif ($currentPeriodEnrollments > 0) {
                $growthRate = 100; // 100% growth if previous period had 0
            }
            
            $result[] = [
                'provider_name' => $provider->name,
                'provider_code' => $provider->code,
                'current_period_enrollments' => $currentPeriodEnrollments,
                'previous_period_enrollments' => $previousPeriodEnrollments,
                'growth_rate' => round($growthRate, 2)
            ];
        }
        
        return $result;
    }
    
    /**
     * Update all provider statistics (for scheduled job)
     */
    public function updateAllProviderStatistics()
    {
        $providers = PosProvider::all();
        $today = now()->format('Y-m-d');
        
        foreach ($providers as $provider) {
            // Get current terminal counts
            $activeCount = $provider->terminals()->where('status', 'active')->count();
            $inactiveCount = $provider->terminals()->where('status', '!=', 'active')->count();
            $totalCount = $activeCount + $inactiveCount;
            
            // Get new enrollments today
            $newEnrollments = $provider->terminals()
                ->whereDate('enrolled_at', $today)
                ->count();
                
            // Update or create statistics record for today
            ProviderStatistics::updateOrCreate(
                ['provider_id' => $provider->id, 'date' => $today],
                [
                    'terminal_count' => $totalCount,
                    'active_terminal_count' => $activeCount,
                    'inactive_terminal_count' => $inactiveCount,
                    'new_enrollments' => $newEnrollments
                ]
            );
        }
    }
}
