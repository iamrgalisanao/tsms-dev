<?php

namespace App\Services;

use App\Models\PosProvider;
use App\Models\ProviderStatistic;
use App\Models\PosTerminal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProviderStatisticsService
{
    public function generateDailyStats($date = null)
    {
        $date = $date ?? now();
        $results = new Collection();

        PosProvider::chunk(100, function($providers) use ($date, &$results) {
            foreach ($providers as $provider) {
                $stats = $this->updateProviderStats($provider, $date);
                $results->push($stats);
            }
        });

        return $results;
    }

    protected function updateProviderStats(PosProvider $provider, $date)
    {
        return ProviderStatistic::updateOrCreate(
            [
                'provider_id' => $provider->id,
                'date' => $date->format('Y-m-d')
            ],
            [
                'terminal_count' => $provider->terminals()->count(),
                'active_terminal_count' => $provider->getActiveTerminalsCountAttribute(),
                'inactive_terminal_count' => $provider->terminals()->where('status', '!=', 'active')->count(),
                'new_enrollments' => $provider->terminals()
                    ->whereDate('enrolled_at', $date->format('Y-m-d'))
                    ->count()
            ]
        );
    }

    public function getProviderDashboardStats()
    {
        return PosProvider::withCount(['terminals', 'terminals as active_terminals_count' => function($query) {
            $query->where('status', 'active');
        }])->get()->map(function($provider) {
            return [
                'id' => $provider->id,
                'name' => $provider->name,
                'code' => $provider->code,
                'total_terminals' => $provider->terminals_count,
                'active_terminals' => $provider->active_terminals_count,
                'growth_rate' => $provider->getGrowthRateAttribute()
            ];
        });
    }

    public function getChartData($providerId)
    {
        try {
            $dates = [];
            $terminalCounts = [];
            $activeCounts = [];
            $newEnrollments = [];

            // Get the last 30 days
            for ($i = 30; $i >= 0; $i--) {
                $date = Carbon::now()->subDays($i)->format('Y-m-d');
                $dates[] = $date;

                // Get total terminals up to this date
                $terminalCounts[] = DB::table('pos_terminals')
                    ->where('provider_id', $providerId)
                    ->whereDate('created_at', '<=', $date)
                    ->count();

                // Get active terminals for this date
                $activeCounts[] = DB::table('pos_terminals')
                    ->where('provider_id', $providerId)
                    ->where('status', 'active')
                    ->whereDate('created_at', '<=', $date)
                    ->count();

                // Get new enrollments for this specific date
                $newEnrollments[] = DB::table('pos_terminals')
                    ->where('provider_id', $providerId)
                    ->whereDate('created_at', $date)
                    ->count();
            }

            return [
                'labels' => $dates,
                'terminalCount' => $terminalCounts,
                'activeCount' => $activeCounts,
                'newEnrollments' => $newEnrollments
            ];
        } catch (\Exception $e) {
            \Log::error('Error generating chart data', [
                'error' => $e->getMessage(),
                'provider_id' => $providerId
            ]);

            return [
                'labels' => [],
                'terminalCount' => [],
                'activeCount' => [],
                'newEnrollments' => []
            ];
        }
    }

    public function getAllProviderStats()
    {
        return ProviderStatistic::with('provider')
            ->select([
                'provider_id',
                DB::raw('MAX(date) as latest_date'),
                DB::raw('SUM(terminal_count) as total_terminals'),
                DB::raw('SUM(active_terminal_count) as active_terminals'),
                DB::raw('SUM(new_enrollments) as total_enrollments')
            ])
            ->groupBy('provider_id')
            ->get()
            ->map(function ($stat) {
                return [
                    'provider' => $stat->provider->name,
                    'latest_date' => Carbon::parse($stat->latest_date)->format('Y-m-d'),
                    'total_terminals' => $stat->total_terminals,
                    'active_terminals' => $stat->active_terminals,
                    'total_enrollments' => $stat->total_enrollments,
                    'active_rate' => $stat->total_terminals > 0 
                        ? round(($stat->active_terminals / $stat->total_terminals) * 100, 2) 
                        : 0
                ];
            });
    }
}