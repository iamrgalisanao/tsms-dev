<?php

namespace App\Services;

use App\Models\PosProvider;
use App\Models\ProviderStatistic;
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
        $startDate = now()->subDays(30)->startOfDay();
        $endDate = now()->endOfDay();

        // Get initial total terminals before start date
        $initialTotal = DB::table('pos_terminals')
            ->where('provider_id', $providerId)
            ->where('enrolled_at', '<', $startDate)
            ->count();

        // Get stats for each day in range
        $data = DB::table('pos_terminals')
            ->where('provider_id', $providerId)
            ->whereBetween('enrolled_at', [$startDate, $endDate])
            ->selectRaw('
                DATE(enrolled_at) as date,
                COUNT(*) as daily_enrollments,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_count
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $chartData = [
            'labels' => [],
            'terminalCount' => [],
            'activeCount' => [],
            'newEnrollments' => []
        ];

        $runningTotal = $initialTotal;

        // Fill in data for each day
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayData = $data->firstWhere('date', $dateStr);
            
            $dailyEnrollments = $dayData ? $dayData->daily_enrollments : 0;
            $runningTotal += $dailyEnrollments;

            $chartData['labels'][] = $currentDate->format('M d');
            $chartData['terminalCount'][] = $runningTotal;
            $chartData['activeCount'][] = $dayData ? $dayData->active_count : 0;
            $chartData['newEnrollments'][] = $dailyEnrollments;

            $currentDate->addDay();
        }

        return $chartData;
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