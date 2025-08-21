<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\SecurityAlertRule;
use App\Models\SecurityAlertResponse;
use App\Services\Security\Contracts\SecurityDashboardInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SecurityDashboardService implements SecurityDashboardInterface
{
    /**
     * Get security dashboard data
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getDashboardData(int $tenantId, array $filters = []): array
    {
        // Set time range - default to last 24 hours if not specified
        $fromDate = isset($filters['from']) 
            ? Carbon::parse($filters['from']) 
            : Carbon::now()->subDay();
            
        $toDate = isset($filters['to']) 
            ? Carbon::parse($filters['to']) 
            : Carbon::now();
        
        // Get event statistics
        $eventStats = $this->getEventsSummary($tenantId, [
            'from' => $fromDate,
            'to' => $toDate
        ]);
        
        // Get alert statistics
        $alertStats = $this->getAlertsSummary($tenantId, [
            'from' => $fromDate,
            'to' => $toDate
        ]);
        
        // Get time series data for events
        $timeSeriesData = $this->getTimeSeriesMetrics($tenantId, 'events_by_hour', [
            'from' => $fromDate,
            'to' => $toDate
        ]);
        
        return [
            'event_stats' => $eventStats,
            'alert_stats' => $alertStats,
            'time_series' => $timeSeriesData,
            'top_ips' => $this->getTopSourceIps($tenantId, $fromDate, $toDate),
            'top_users' => $this->getTopAffectedUsers($tenantId, $fromDate, $toDate),
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }

    /**
     * Get security events summary
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getEventsSummary(int $tenantId, array $filters = []): array
    {
        $query = SecurityEvent::where('tenant_id', $tenantId);
        
        if (isset($filters['from'])) {
            $query->where('event_timestamp', '>=', $filters['from']);
        }
        
        if (isset($filters['to'])) {
            $query->where('event_timestamp', '<=', $filters['to']);
        }
        
        if (isset($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        
        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        
        // Get total events
        $totalEvents = $query->count();
        
        // Get events by type
        $eventsByType = DB::table('security_events')
            ->select('event_type', DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['from']), function ($q) use ($filters) {
                return $q->where('event_timestamp', '>=', $filters['from']);
            })
            ->when(isset($filters['to']), function ($q) use ($filters) {
                return $q->where('event_timestamp', '<=', $filters['to']);
            })
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type')
            ->map(function ($item) {
                return $item->count;
            })
            ->toArray();
        
        // Get events by severity
        $eventsBySeverity = DB::table('security_events')
            ->select('severity', DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['from']), function ($q) use ($filters) {
                return $q->where('event_timestamp', '>=', $filters['from']);
            })
            ->when(isset($filters['to']), function ($q) use ($filters) {
                return $q->where('event_timestamp', '<=', $filters['to']);
            })
            ->groupBy('severity')
            ->get()
            ->keyBy('severity')
            ->map(function ($item) {
                return $item->count;
            })
            ->toArray();
        
        return [
            'total_events' => $totalEvents,
            'events_by_type' => $eventsByType,
            'events_by_severity' => $eventsBySeverity,
            'latest_events' => SecurityEvent::where('tenant_id', $tenantId)
                ->when(isset($filters['from']), function ($q) use ($filters) {
                    return $q->where('event_timestamp', '>=', $filters['from']);
                })
                ->when(isset($filters['to']), function ($q) use ($filters) {
                    return $q->where('event_timestamp', '<=', $filters['to']);
                })
                ->orderBy('event_timestamp', 'desc')
                ->limit(5)
                ->get()
                ->toArray()
        ];
    }

    /**
     * Get security alerts summary
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getAlertsSummary(int $tenantId, array $filters = []): array
    {
        // Get active alert rules
        $alertRules = SecurityAlertRule::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();
        
        // Get alert responses stats
        $responses = SecurityAlertResponse::where('tenant_id', $tenantId)
            ->when(isset($filters['from']), function ($q) use ($filters) {
                return $q->where('created_at', '>=', $filters['from']);
            })
            ->when(isset($filters['to']), function ($q) use ($filters) {
                return $q->where('created_at', '<=', $filters['to']);
            })
            ->get();
        
        $responsesByStatus = $responses->groupBy('status')
            ->map(function ($items) {
                return $items->count();
            })
            ->toArray();
        
        return [
            'total_alert_rules' => $alertRules->count(),
            'active_alert_rules' => $alertRules->where('is_active', true)->count(),
            'total_responses' => $responses->count(),
            'responses_by_status' => $responsesByStatus,
            'latest_responses' => SecurityAlertResponse::where('tenant_id', $tenantId)
                ->when(isset($filters['from']), function ($q) use ($filters) {
                    return $q->where('created_at', '>=', $filters['from']);
                })
                ->when(isset($filters['to']), function ($q) use ($filters) {
                    return $q->where('created_at', '<=', $filters['to']);
                })
                ->with('alertRule')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->toArray()
        ];
    }

    /**
     * Get security metrics over time
     * 
     * @param int $tenantId
     * @param string $metricType
     * @param array $params
     * @return array
     */
    public function getTimeSeriesMetrics(int $tenantId, string $metricType, array $params = []): array
    {
        $fromDate = isset($params['from']) 
            ? Carbon::parse($params['from']) 
            : Carbon::now()->subDay();
            
        $toDate = isset($params['to']) 
            ? Carbon::parse($params['to']) 
            : Carbon::now();
        
        switch ($metricType) {
            case 'events_by_hour':
                return $this->getEventsTimeSeriesByHour($tenantId, $fromDate, $toDate);
            
            case 'events_by_day':
                return $this->getEventsTimeSeriesByDay($tenantId, $fromDate, $toDate);
            
            case 'events_by_type':
                return $this->getEventsTimeSeriesByType($tenantId, $fromDate, $toDate);
            
            case 'events_by_severity':
                return $this->getEventsTimeSeriesBySeverity($tenantId, $fromDate, $toDate);
            
            default:
                return [];
        }
    }

    /**
     * Get events time series by hour
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function getEventsTimeSeriesByHour(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $timeSeries = DB::table('security_events')
            ->select(DB::raw('DATE_FORMAT(event_timestamp, "%Y-%m-%d %H:00:00") as hour'), DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();
        
        return [
            'metric' => 'events_by_hour',
            'data' => $timeSeries->map(function ($item) {
                return [
                    'time' => $item->hour,
                    'count' => $item->count
                ];
            })->toArray()
        ];
    }

    /**
     * Get events time series by day
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function getEventsTimeSeriesByDay(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $timeSeries = DB::table('security_events')
            ->select(DB::raw('DATE(event_timestamp) as date'), DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return [
            'metric' => 'events_by_day',
            'data' => $timeSeries->map(function ($item) {
                return [
                    'time' => $item->date,
                    'count' => $item->count
                ];
            })->toArray()
        ];
    }

    /**
     * Get events time series by type
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function getEventsTimeSeriesByType(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $timeSeries = DB::table('security_events')
            ->select('event_type', DB::raw('DATE(event_timestamp) as date'), DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->groupBy('event_type', 'date')
            ->orderBy('date')
            ->get();
        
        $eventTypes = SecurityEvent::where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('event_type')
            ->toArray();
        
        $result = [];
        
        foreach ($eventTypes as $type) {
            $result[$type] = $timeSeries->where('event_type', $type)
                ->map(function ($item) {
                    return [
                        'time' => $item->date,
                        'count' => $item->count
                    ];
                })
                ->toArray();
        }
        
        return [
            'metric' => 'events_by_type',
            'event_types' => $eventTypes,
            'data' => $result
        ];
    }

    /**
     * Get events time series by severity
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function getEventsTimeSeriesBySeverity(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $timeSeries = DB::table('security_events')
            ->select('severity', DB::raw('DATE(event_timestamp) as date'), DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->groupBy('severity', 'date')
            ->orderBy('date')
            ->get();
        
        $severities = ['info', 'warning', 'critical'];
        
        $result = [];
        
        foreach ($severities as $severity) {
            $result[$severity] = $timeSeries->where('severity', $severity)
                ->map(function ($item) {
                    return [
                        'time' => $item->date,
                        'count' => $item->count
                    ];
                })
                ->toArray();
        }
        
        return [
            'metric' => 'events_by_severity',
            'severities' => $severities,
            'data' => $result
        ];
    }

    /**
     * Get top source IPs by event count
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function getTopSourceIps(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        return DB::table('security_events')
            ->select('source_ip', DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->whereNotNull('source_ip')
            ->groupBy('source_ip')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->toArray();
    }

    /**
     * Get top affected users by event count
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */    private function getTopAffectedUsers(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        return DB::table('security_events')
            ->select('user_id', DB::raw('count(*) as count'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->toArray();
    }
    
    /**
     * Get advanced visualization data
     * 
     * @param int $tenantId
     * @param string $visualizationType
     * @param array $params
     * @return array
     */
    public function getAdvancedVisualizationData(int $tenantId, string $visualizationType, array $params = []): array
    {
        // Set time range
        $fromDate = isset($params['from']) 
            ? Carbon::parse($params['from']) 
            : Carbon::now()->subDays(30);
            
        $toDate = isset($params['to']) 
            ? Carbon::parse($params['to']) 
            : Carbon::now();
            
        switch ($visualizationType) {
            case 'threat_map':
                return $this->generateThreatMap($tenantId, $fromDate, $toDate);
            case 'attack_vectors':
                return $this->generateAttackVectorAnalysis($tenantId, $fromDate, $toDate);
            case 'severity_trends':
                return $this->generateSeverityTrends($tenantId, $fromDate, $toDate);
            case 'user_activity_patterns':
                return $this->generateUserActivityPatterns($tenantId, $fromDate, $toDate);
            case 'correlation_matrix':
                return $this->generateCorrelationMatrix($tenantId, $fromDate, $toDate);
            default:
                return [
                    'error' => 'Unknown visualization type'
                ];
        }
    }
    
    /**
     * Generate threat map data - geographical distribution of security events
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateThreatMap(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $query = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->whereNotNull('source_ip')
            ->orderBy('event_timestamp', 'desc');
            
        $events = $query->get();
        
        // Process IP data into geographical information
        $geoData = [];
        $uniqueIps = [];
        
        foreach ($events as $event) {
            $ip = $event->source_ip;
            
            // Skip if already processed or internal IP
            if (in_array($ip, $uniqueIps) || $this->isInternalIp($ip)) {
                continue;
            }
            
            $uniqueIps[] = $ip;
            
            // Get geolocation data
            $geoInfo = $this->getIpGeoLocation($ip);
            if ($geoInfo) {
                // Add to geo data if we have valid location
                if (isset($geoInfo['latitude']) && isset($geoInfo['longitude'])) {
                    $geoData[] = [
                        'ip' => $ip,
                        'latitude' => $geoInfo['latitude'],
                        'longitude' => $geoInfo['longitude'],
                        'country' => $geoInfo['country'] ?? 'Unknown',
                        'city' => $geoInfo['city'] ?? 'Unknown',
                        'event_count' => $events->where('source_ip', $ip)->count(),
                        'last_seen' => $events->where('source_ip', $ip)->first()->event_timestamp,
                        'severity' => $this->getHighestSeverityForIp($events, $ip)
                    ];
                }
            }
        }
        
        return [
            'type' => 'threat_map',
            'data' => $geoData,
            'total_locations' => count($geoData),
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }
    
    /**
     * Check if IP is internal
     * 
     * @param string $ip
     * @return bool
     */
    private function isInternalIp(string $ip): bool
    {
        return filter_var(
            $ip, 
            FILTER_VALIDATE_IP, 
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
    
    /**
     * Get geolocation for IP address
     * 
     * @param string $ip
     * @return array|null
     */
    private function getIpGeoLocation(string $ip): ?array
    {
        // This is a placeholder - in a real implementation you would:
        // 1. Check a local cache first
        // 2. Use a geolocation service API
        // 3. Store results for future use
        
        // For demonstration, return random coordinates
        // In production, integrate with a service like MaxMind GeoIP or IP-API
        
        // Random coordinates for demonstration
        return [
            'latitude' => rand(-90, 90) + (rand(0, 1000) / 1000),
            'longitude' => rand(-180, 180) + (rand(0, 1000) / 1000),
            'country' => ['United States', 'China', 'Russia', 'Germany', 'Brazil', 'India'][rand(0, 5)],
            'city' => ['New York', 'Beijing', 'Moscow', 'Berlin', 'São Paulo', 'Mumbai'][rand(0, 5)]
        ];
    }
    
    /**
     * Get highest severity level for a given IP
     * 
     * @param \Illuminate\Support\Collection $events
     * @param string $ip
     * @return string
     */
    private function getHighestSeverityForIp($events, string $ip): string
    {
        $severityLevels = ['critical', 'high', 'medium', 'low', 'info'];
        
        foreach ($severityLevels as $severity) {
            if ($events->where('source_ip', $ip)->where('severity', $severity)->count() > 0) {
                return $severity;
            }
        }
        
        return 'info';
    }
    
    /**
     * Generate attack vector analysis
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateAttackVectorAnalysis(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $events = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->get();
            
        // Group events by attack vector/type
        $attackVectors = $events->groupBy('event_type')->map(function ($items) {
            return [
                'count' => $items->count(),
                'severity_distribution' => $items->groupBy('severity')
                    ->map(function ($severityItems) {
                        return $severityItems->count();
                    }),
                'success_rate' => $items->where('context->success', true)->count() / max(1, $items->count())
            ];
        });
        
        return [
            'type' => 'attack_vectors',
            'data' => $attackVectors,
            'total_events' => $events->count(),
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }
    
    /**
     * Generate severity trends over time
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateSeverityTrends(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        // Determine appropriate grouping based on date range
        $diffInDays = $fromDate->diffInDays($toDate);
        
        if ($diffInDays <= 2) {
            // Group by hour for small ranges
            $groupFormat = 'Y-m-d H:00';
            $interval = 'hour';
        } elseif ($diffInDays <= 31) {
            // Group by day for medium ranges
            $groupFormat = 'Y-m-d';
            $interval = 'day';
        } else {
            // Group by week for large ranges
            $groupFormat = 'Y-W'; // Year and week number
            $interval = 'week';
        }
        
        // Get events grouped by time and severity
        $events = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->get();
        
        // Generate all intervals in the date range
        $timeline = [];
        $current = clone $fromDate;
        
        while ($current <= $toDate) {
            $timeKey = $current->format($groupFormat);
            $timeline[$timeKey] = [
                'critical' => 0,
                'high' => 0,
                'medium' => 0,
                'low' => 0,
                'info' => 0,
                'total' => 0
            ];
            
            // Advance to next interval
            if ($interval === 'hour') {
                $current->addHours(1);
            } elseif ($interval === 'day') {
                $current->addDays(1);
            } else {
                $current->addWeeks(1);
            }
        }
        
        // Populate with actual data
        foreach ($events as $event) {
            $timeKey = Carbon::parse($event->event_timestamp)->format($groupFormat);
            $severity = $event->severity ?? 'info';
            
            if (isset($timeline[$timeKey])) {
                $timeline[$timeKey][$severity]++;
                $timeline[$timeKey]['total']++;
            }
        }
        
        return [
            'type' => 'severity_trends',
            'interval' => $interval,
            'data' => $timeline,
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }
    
    /**
     * Generate user activity patterns
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateUserActivityPatterns(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $events = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->whereNotNull('user_id')
            ->get();
        
        // Get top users by event count
        $topUsers = $events->groupBy('user_id')
            ->map(function ($items) {
                return [
                    'event_count' => $items->count(),
                    'unique_ips' => $items->pluck('source_ip')->unique()->count(),
                    'last_activity' => $items->sortByDesc('event_timestamp')->first()->event_timestamp,
                    'activity_hours' => $items->groupBy(function ($item) {
                        return Carbon::parse($item->event_timestamp)->format('H');
                    })->map->count(),
                    'severity_counts' => $items->groupBy('severity')->map->count()
                ];
            })
            ->sortByDesc(function ($userData) {
                return $userData['event_count'];
            })
            ->take(10);
        
        return [
            'type' => 'user_activity_patterns',
            'data' => $topUsers,
            'total_users' => $events->pluck('user_id')->unique()->count(),
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }
    
    /**
     * Generate correlation matrix between different security events
     * 
     * @param int $tenantId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    private function generateCorrelationMatrix(int $tenantId, Carbon $fromDate, Carbon $toDate): array
    {
        $events = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$fromDate, $toDate])
            ->get();
        
        // Get all unique event types
        $eventTypes = $events->pluck('event_type')->unique()->values()->all();
        
        // Initialize correlation matrix
        $matrix = [];
        foreach ($eventTypes as $type1) {
            $matrix[$type1] = [];
            foreach ($eventTypes as $type2) {
                $matrix[$type1][$type2] = 0;
            }
        }
        
        // Group events by day and user to find correlations
        $eventsByDayAndUser = $events->groupBy(function ($event) {
            $day = Carbon::parse($event->event_timestamp)->format('Y-m-d');
            $user = $event->user_id ?? 'anonymous';
            return "{$day}_{$user}";
        });
        
        // Calculate correlation scores
        foreach ($eventsByDayAndUser as $dayUser => $dayUserEvents) {
            $typesInDay = $dayUserEvents->pluck('event_type')->unique()->values()->all();
            
            // If multiple event types occurred for same user on same day, increment correlation
            if (count($typesInDay) > 1) {
                for ($i = 0; $i < count($typesInDay); $i++) {
                    for ($j = $i + 1; $j < count($typesInDay); $j++) {
                        $type1 = $typesInDay[$i];
                        $type2 = $typesInDay[$j];
                        
                        // Increment correlation count in both directions
                        if (isset($matrix[$type1][$type2])) {
                            $matrix[$type1][$type2]++;
                        }
                        
                        if (isset($matrix[$type2][$type1])) {
                            $matrix[$type2][$type1]++;
                        }
                    }
                }
            }
        }
        
        return [
            'type' => 'correlation_matrix',
            'event_types' => $eventTypes,
            'matrix' => $matrix,
            'time_range' => [
                'from' => $fromDate->toIso8601String(),
                'to' => $toDate->toIso8601String()
            ]
        ];
    }
}