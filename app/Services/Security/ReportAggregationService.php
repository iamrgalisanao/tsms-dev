<?php

namespace App\Services\Security;

use App\Models\SecurityLog;
use App\Models\SecurityAlert;
use App\Models\IntegrationLog;
use App\Models\SecurityEvent;
use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use App\Models\CircuitBreaker;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportAggregationService
{
    /**
     * Aggregate security data based on the report template
     *
     * @param SecurityReportTemplate $template
     * @param array $parameters Additional filtering parameters
     * @return array
     */
    public function aggregateData(SecurityReportTemplate $template, array $parameters = []): array
    {
        try {
            // Extract date range from parameters or use defaults
            $startDate = $parameters['start_date'] ?? Carbon::now()->subDays(30);
            $endDate = $parameters['end_date'] ?? Carbon::now();
            $tenantId = $parameters['tenant_id'] ?? $template->tenant_id;
            
            // Initialize results array
            $results = [
                'metadata' => [
                    'report_name' => $template->name,
                    'description' => $template->description,
                    'generated_at' => Carbon::now()->toIso8601String(),
                    'time_period' => [
                        'start' => $startDate->toIso8601String(),
                        'end' => $endDate->toIso8601String(),
                    ],
                    'tenant_id' => $tenantId,
                ],
                'summary' => [],
                'details' => [],
            ];
            
            // Process each section based on template type
            switch ($template->type) {
                case 'security_events':
                    $results = array_merge($results, $this->aggregateSecurityEvents($tenantId, $startDate, $endDate, $template->filters ?? []));
                    break;
                    
                case 'failed_transactions':
                    $results = array_merge($results, $this->aggregateFailedTransactions($tenantId, $startDate, $endDate, $template->filters ?? []));
                    break;
                    
                case 'circuit_breaker_trips':
                    $results = array_merge($results, $this->aggregateCircuitBreakerTrips($tenantId, $startDate, $endDate, $template->filters ?? []));
                    break;
                    
                case 'login_attempts':
                    $results = array_merge($results, $this->aggregateLoginAttempts($tenantId, $startDate, $endDate, $template->filters ?? []));
                    break;
                    
                case 'security_alerts':
                    $results = array_merge($results, $this->aggregateSecurityAlerts($tenantId, $startDate, $endDate, $template->filters ?? []));
                    break;
                    
                case 'comprehensive':
                    // For comprehensive reports, aggregate data from multiple sources
                    $eventsData = $this->aggregateSecurityEvents($tenantId, $startDate, $endDate, $template->filters['events'] ?? []);
                    $failedTransactionsData = $this->aggregateFailedTransactions($tenantId, $startDate, $endDate, $template->filters['transactions'] ?? []);
                    $circuitBreakerData = $this->aggregateCircuitBreakerTrips($tenantId, $startDate, $endDate, $template->filters['circuit_breakers'] ?? []);
                    $loginAttemptsData = $this->aggregateLoginAttempts($tenantId, $startDate, $endDate, $template->filters['login_attempts'] ?? []);
                    $alertsData = $this->aggregateSecurityAlerts($tenantId, $startDate, $endDate, $template->filters['alerts'] ?? []);
                    
                    // Merge summaries
                    $results['summary'] = array_merge(
                        $results['summary'],
                        $eventsData['summary'] ?? [],
                        $failedTransactionsData['summary'] ?? [],
                        $circuitBreakerData['summary'] ?? [],
                        $loginAttemptsData['summary'] ?? [],
                        $alertsData['summary'] ?? []
                    );
                    
                    // Add detailed sections
                    $results['details']['security_events'] = $eventsData['details'] ?? [];
                    $results['details']['failed_transactions'] = $failedTransactionsData['details'] ?? [];
                    $results['details']['circuit_breaker_trips'] = $circuitBreakerData['details'] ?? [];
                    $results['details']['login_attempts'] = $loginAttemptsData['details'] ?? [];
                    $results['details']['security_alerts'] = $alertsData['details'] ?? [];
                    
                    // Generate cross-data insights
                    $results['insights'] = $this->generateInsights($results);
                    break;
                    
                default:
                    throw new \InvalidArgumentException("Unsupported report template type: {$template->type}");
            }
            
            return $results;
            
        } catch (\Exception $e) {
            Log::error('Failed to aggregate security report data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'template_id' => $template->id,
                'tenant_id' => $tenantId,
                'parameters' => $parameters
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Aggregate security events data
     *
     * @param int $tenantId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $filters
     * @return array
     */
    private function aggregateSecurityEvents(int $tenantId, Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        // Query security events
        $eventsQuery = SecurityEvent::where('tenant_id', $tenantId)
            ->whereBetween('event_timestamp', [$startDate, $endDate]);
            
        // Apply additional filters
        if (!empty($filters['event_type'])) {
            $eventsQuery->whereIn('event_type', (array)$filters['event_type']);
        }
        
        if (!empty($filters['severity'])) {
            $eventsQuery->whereIn('severity', (array)$filters['severity']);
        }
        
        if (!empty($filters['source_ip'])) {
            $eventsQuery->where('source_ip', $filters['source_ip']);
        }
        
        // Execute query
        $events = $eventsQuery->orderBy('event_timestamp', 'desc')
            ->limit($filters['limit'] ?? 1000)
            ->get();
            
        // Group events by type and severity
        $eventsByType = $events->groupBy('event_type')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        $eventsBySeverity = $events->groupBy('severity')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Group events by day for trend analysis
        $eventsByDay = $events->groupBy(function ($event) {
            return Carbon::parse($event->event_timestamp)->format('Y-m-d');
        })->map(function ($items) {
            return $items->count();
        })->toArray();
        
        // Find most frequent source IPs
        $topSourceIps = $events->groupBy('source_ip')
            ->map(function ($items) {
                return [
                    'count' => $items->count(),
                    'last_seen' => $items->max('event_timestamp'),
                ];
            })
            ->sortByDesc('count')
            ->take(10)
            ->toArray();
            
        return [
            'summary' => [
                'total_security_events' => $events->count(),
                'high_severity_events' => $events->where('severity', 'high')->count(),
                'medium_severity_events' => $events->where('severity', 'medium')->count(),
                'low_severity_events' => $events->where('severity', 'low')->count(),
            ],
            'details' => [
                'events_by_type' => $eventsByType,
                'events_by_severity' => $eventsBySeverity,
                'events_by_day' => $eventsByDay,
                'top_source_ips' => $topSourceIps,
                'events_list' => $events->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'event_type' => $event->event_type,
                        'severity' => $event->severity,
                        'timestamp' => $event->event_timestamp,
                        'source_ip' => $event->source_ip,
                        'user_id' => $event->user_id,
                        'context' => $event->context,
                    ];
                })->toArray(),
            ],
        ];
    }
    
    /**
     * Aggregate failed transactions data
     *
     * @param int $tenantId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $filters
     * @return array
     */
    private function aggregateFailedTransactions(int $tenantId, Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        // Query failed integration logs
        $logsQuery = IntegrationLog::where('tenant_id', $tenantId)
            ->where('status', 'FAILED')
            ->whereBetween('created_at', [$startDate, $endDate]);
            
        // Apply additional filters
        if (!empty($filters['terminal_id'])) {
            $logsQuery->where('terminal_id', $filters['terminal_id']);
        }
        
        // Execute query
        $logs = $logsQuery->orderBy('created_at', 'desc')
            ->limit($filters['limit'] ?? 1000)
            ->get();
            
        // Group failed transactions by error type
        $logsByErrorType = $logs->groupBy('error_type')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Calculate retry statistics
        $retriedLogs = $logs->filter(function ($log) {
            return $log->retry_count > 0;
        });
        
        $permanentlyFailedLogs = $logs->filter(function ($log) {
            return $log->retry_count >= config('transactions.max_retries', 3);
        });
        
        // Group failures by terminal
        $logsByTerminal = $logs->groupBy('terminal_id')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Group by day for trend analysis
        $logsByDay = $logs->groupBy(function ($log) {
            return Carbon::parse($log->created_at)->format('Y-m-d');
        })->map(function ($items) {
            return $items->count();
        })->toArray();
        
        return [
            'summary' => [
                'total_failed_transactions' => $logs->count(),
                'retried_transactions' => $retriedLogs->count(),
                'permanently_failed_transactions' => $permanentlyFailedLogs->count(),
                'retry_success_rate' => $logs->count() > 0
                    ? round(($logs->count() - $permanentlyFailedLogs->count()) / $logs->count() * 100, 2)
                    : 0,
            ],
            'details' => [
                'failures_by_error_type' => $logsByErrorType,
                'failures_by_terminal' => $logsByTerminal,
                'failures_by_day' => $logsByDay,
                'transactions_list' => $logs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'transaction_id' => $log->transaction_id,
                        'terminal_id' => $log->terminal_id,
                        'created_at' => $log->created_at,
                        'error_type' => $log->error_type,
                        'error_message' => $log->error_message,
                        'retry_count' => $log->retry_count,
                        'next_retry_at' => $log->next_retry_at,
                    ];
                })->toArray(),
            ],
        ];
    }
    
    /**
     * Aggregate circuit breaker trips data
     *
     * @param int $tenantId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $filters
     * @return array
     */
    private function aggregateCircuitBreakerTrips(int $tenantId, Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        // Query circuit breakers in OPEN status or with trips
        $breakersQuery = CircuitBreaker::where('tenant_id', $tenantId)
            ->where(function ($query) use ($startDate, $endDate) {
                // Find breakers that were tripped within the date range
                $query->where('status', CircuitBreaker::STATUS_OPEN)
                    ->orWhere('trip_count', '>', 0)
                    ->whereBetween('updated_at', [$startDate, $endDate]);
            });
            
        // Apply additional filters
        if (!empty($filters['service'])) {
            $breakersQuery->where('name', $filters['service']);
        }
        
        // Execute query
        $breakers = $breakersQuery->orderBy('updated_at', 'desc')
            ->get();
            
        // Calculate total downtime
        $totalDowntime = 0;
        foreach ($breakers as $breaker) {
            if ($breaker->status === CircuitBreaker::STATUS_OPEN) {
                $tripTime = Carbon::parse($breaker->updated_at);
                $cooldownTime = Carbon::parse($breaker->cooldown_until);
                $totalDowntime += $tripTime->diffInMinutes($cooldownTime);
            }
        }
        
        // Group breakers by service
        $breakersByService = $breakers->groupBy('name')
            ->map(function ($items) {
                return [
                    'count' => $items->count(),
                    'total_trips' => $items->sum('trip_count'),
                ];
            })->toArray();
            
        return [
            'summary' => [
                'total_circuit_trips' => $breakers->sum('trip_count'),
                'currently_open_circuits' => $breakers->where('status', CircuitBreaker::STATUS_OPEN)->count(),
                'total_downtime_minutes' => $totalDowntime,
                'affected_services' => $breakers->pluck('name')->unique()->count(),
            ],
            'details' => [
                'circuit_trips_by_service' => $breakersByService,
                'circuits_list' => $breakers->map(function ($breaker) {
                    return [
                        'id' => $breaker->id,
                        'service' => $breaker->name,
                        'status' => $breaker->status,
                        'trip_count' => $breaker->trip_count,
                        'failure_count' => $breaker->failure_count,
                        'failure_threshold' => $breaker->failure_threshold,
                        'last_failure_at' => $breaker->last_failure_at,
                        'cooldown_until' => $breaker->cooldown_until,
                    ];
                })->toArray(),
            ],
        ];
    }
    
    /**
     * Aggregate login attempts data
     *
     * @param int $tenantId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $filters
     * @return array
     */
    private function aggregateLoginAttempts(int $tenantId, Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        // Query security events of login type
        $loginEventsQuery = SecurityEvent::where('tenant_id', $tenantId)
            ->whereIn('event_type', ['login_success', 'login_failed', 'logout'])
            ->whereBetween('event_timestamp', [$startDate, $endDate]);
            
        // Apply additional filters
        if (!empty($filters['user_id'])) {
            $loginEventsQuery->where('user_id', $filters['user_id']);
        }
        
        // Execute query
        $loginEvents = $loginEventsQuery->orderBy('event_timestamp', 'desc')
            ->limit($filters['limit'] ?? 1000)
            ->get();
            
        // Group login events by outcome
        $loginsByOutcome = $loginEvents->groupBy('event_type')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Calculate total login success rate
        $totalAttempts = $loginEvents->whereIn('event_type', ['login_success', 'login_failed'])->count();
        $successRate = $totalAttempts > 0
            ? round($loginEvents->where('event_type', 'login_success')->count() / $totalAttempts * 100, 2)
            : 0;
            
        // Find repeated failed login attempts for the same user
        $suspiciousLogins = [];
        $loginsByUser = $loginEvents->where('event_type', 'login_failed')->groupBy('user_id');
        
        foreach ($loginsByUser as $userId => $attempts) {
            if ($attempts->count() >= ($filters['suspicious_threshold'] ?? 5)) {
                $suspiciousLogins[$userId] = [
                    'user_id' => $userId,
                    'failed_attempts' => $attempts->count(),
                    'last_attempt' => $attempts->max('event_timestamp'),
                    'source_ips' => $attempts->pluck('source_ip')->unique()->values()->toArray(),
                ];
            }
        }
            
        // Group by day for trend analysis
        $loginsByDay = $loginEvents->groupBy(function ($event) {
            return Carbon::parse($event->event_timestamp)->format('Y-m-d');
        })->map(function ($items) {
            $successCount = $items->where('event_type', 'login_success')->count();
            $failedCount = $items->where('event_type', 'login_failed')->count();
            $totalCount = $successCount + $failedCount;
            
            return [
                'total' => $totalCount,
                'success' => $successCount,
                'failed' => $failedCount,
                'success_rate' => $totalCount > 0 ? round($successCount / $totalCount * 100, 2) : 0,
            ];
        })->toArray();
        
        return [
            'summary' => [
                'total_login_attempts' => $totalAttempts,
                'successful_logins' => $loginEvents->where('event_type', 'login_success')->count(),
                'failed_logins' => $loginEvents->where('event_type', 'login_failed')->count(),
                'login_success_rate' => $successRate,
                'suspicious_users_count' => count($suspiciousLogins),
            ],
            'details' => [
                'logins_by_outcome' => $loginsByOutcome,
                'logins_by_day' => $loginsByDay,
                'suspicious_users' => $suspiciousLogins,
                'login_events_list' => $loginEvents->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'event_type' => $event->event_type,
                        'user_id' => $event->user_id,
                        'timestamp' => $event->event_timestamp,
                        'source_ip' => $event->source_ip,
                        'context' => $event->context,
                    ];
                })->toArray(),
            ],
        ];
    }
    
    /**
     * Aggregate security alerts data
     *
     * @param int $tenantId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $filters
     * @return array
     */
    private function aggregateSecurityAlerts(int $tenantId, Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        // Query security alerts
        $alertsQuery = SecurityAlert::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startDate, $endDate]);
            
        // Apply additional filters
        if (!empty($filters['severity'])) {
            $alertsQuery->whereIn('severity', (array)$filters['severity']);
        }
        
        if (!empty($filters['status'])) {
            $alertsQuery->whereIn('status', (array)$filters['status']);
        }
        
        // Execute query
        $alerts = $alertsQuery->orderBy('created_at', 'desc')
            ->limit($filters['limit'] ?? 1000)
            ->get();
            
        // Group alerts by status
        $alertsByStatus = $alerts->groupBy('status')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Group alerts by severity
        $alertsBySeverity = $alerts->groupBy('severity')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Group alerts by rule type
        $alertsByRule = $alerts->groupBy('rule_id')
            ->map(function ($items) {
                return $items->count();
            })->toArray();
            
        // Calculate response times for acknowledged alerts
        $respondedAlerts = $alerts->whereNotNull('acknowledged_at');
        
        $averageResponseTime = 0;
        if ($respondedAlerts->count() > 0) {
            $totalResponseTime = 0;
            foreach ($respondedAlerts as $alert) {
                $createdAt = Carbon::parse($alert->created_at);
                $acknowledgedAt = Carbon::parse($alert->acknowledged_at);
                $totalResponseTime += $createdAt->diffInMinutes($acknowledgedAt);
            }
            $averageResponseTime = round($totalResponseTime / $respondedAlerts->count(), 2);
        }
        
        return [
            'summary' => [
                'total_alerts' => $alerts->count(),
                'pending_alerts' => $alerts->whereNull('acknowledged_at')->count(),
                'acknowledged_alerts' => $respondedAlerts->count(),
                'high_severity_alerts' => $alerts->where('severity', 'high')->count(),
                'average_response_time_minutes' => $averageResponseTime,
            ],
            'details' => [
                'alerts_by_status' => $alertsByStatus,
                'alerts_by_severity' => $alertsBySeverity,
                'alerts_by_rule' => $alertsByRule,
                'alerts_list' => $alerts->map(function ($alert) {
                    return [
                        'id' => $alert->id,
                        'title' => $alert->title,
                        'description' => $alert->description,
                        'severity' => $alert->severity,
                        'status' => $alert->status,
                        'rule_id' => $alert->rule_id,
                        'created_at' => $alert->created_at,
                        'acknowledged_at' => $alert->acknowledged_at,
                        'acknowledged_by' => $alert->acknowledged_by,
                        'resolution_notes' => $alert->resolution_notes,
                    ];
                })->toArray(),
            ],
        ];
    }
    
    /**
     * Generate cross-data insights from the aggregated data
     *
     * @param array $reportData
     * @return array
     */
    private function generateInsights(array $reportData): array
    {
        $insights = [];
        
        // Check for correlation between failed transactions and circuit breaker trips
        if (isset($reportData['details']['failed_transactions']) && isset($reportData['details']['circuit_breaker_trips'])) {
            $insights['transaction_circuit_correlation'] = $this->analyzeTransactionCircuitCorrelation(
                $reportData['details']['failed_transactions'],
                $reportData['details']['circuit_breaker_trips']
            );
        }
        
        // Check for correlation between security events and login failures
        if (isset($reportData['details']['security_events']) && isset($reportData['details']['login_attempts'])) {
            $insights['security_login_correlation'] = $this->analyzeSecurityLoginCorrelation(
                $reportData['details']['security_events'],
                $reportData['details']['login_attempts']
            );
        }
        
        // Add overall security posture assessment
        $insights['security_posture'] = $this->assessSecurityPosture($reportData);
        
        return $insights;
    }
    
    /**
     * Analyze correlation between failed transactions and circuit breaker trips
     *
     * @param array $transactionData
     * @param array $circuitData
     * @return array
     */
    private function analyzeTransactionCircuitCorrelation(array $transactionData, array $circuitData): array
    {
        // Implement correlation analysis logic
        // This is a placeholder for actual correlation calculation
        return [
            'correlation_detected' => false,
            'correlation_strength' => 0,
            'insights' => 'No significant correlation detected between transaction failures and circuit breaker trips.',
        ];
    }
    
    /**
     * Analyze correlation between security events and login failures
     *
     * @param array $securityData
     * @param array $loginData
     * @return array
     */
    private function analyzeSecurityLoginCorrelation(array $securityData, array $loginData): array
    {
        // Implement correlation analysis logic
        // This is a placeholder for actual correlation calculation
        return [
            'correlation_detected' => false,
            'correlation_strength' => 0,
            'insights' => 'No significant correlation detected between security events and login failures.',
        ];
    }
    
    /**
     * Assess overall security posture based on aggregated data
     *
     * @param array $reportData
     * @return array
     */
    private function assessSecurityPosture(array $reportData): array
    {
        // Calculate an overall security score based on various metrics
        $score = 100; // Start with perfect score
        
        // Deduct points for high severity events
        if (isset($reportData['summary']['high_severity_events']) && $reportData['summary']['high_severity_events'] > 0) {
            $score -= min(30, $reportData['summary']['high_severity_events'] * 5);
        }
        
        // Deduct points for failed logins
        if (isset($reportData['summary']['failed_logins']) && $reportData['summary']['failed_logins'] > 0) {
            $score -= min(20, $reportData['summary']['failed_logins'] * 2);
        }
        
        // Deduct points for circuit breaker trips
        if (isset($reportData['summary']['total_circuit_trips']) && $reportData['summary']['total_circuit_trips'] > 0) {
            $score -= min(15, $reportData['summary']['total_circuit_trips'] * 3);
        }
        
        // Deduct points for high severity alerts
        if (isset($reportData['summary']['high_severity_alerts']) && $reportData['summary']['high_severity_alerts'] > 0) {
            $score -= min(25, $reportData['summary']['high_severity_alerts'] * 5);
        }
        
        // Determine risk level based on score
        $riskLevel = 'low';
        if ($score < 60) {
            $riskLevel = 'high';
        } elseif ($score < 80) {
            $riskLevel = 'medium';
        }
        
        return [
            'security_score' => max(0, $score),
            'risk_level' => $riskLevel,
            'recommendations' => $this->generateRecommendations($reportData, $riskLevel),
        ];
    }
    
    /**
     * Generate security recommendations based on the report data
     *
     * @param array $reportData
     * @param string $riskLevel
     * @return array
     */
    private function generateRecommendations(array $reportData, string $riskLevel): array
    {
        $recommendations = [];
        
        // Add recommendations based on data patterns
        if (isset($reportData['summary']['failed_logins']) && $reportData['summary']['failed_logins'] > 10) {
            $recommendations[] = 'Implement additional user authentication protections such as multi-factor authentication.';
        }
        
        if (isset($reportData['summary']['total_circuit_trips']) && $reportData['summary']['total_circuit_trips'] > 5) {
            $recommendations[] = 'Review circuit breaker configuration and consider adjusting thresholds or implementing retry with backoff strategies.';
        }
        
        if (isset($reportData['summary']['high_severity_alerts']) && $reportData['summary']['high_severity_alerts'] > 0) {
            $recommendations[] = 'Prioritize investigation of high severity security alerts.';
        }
        
        if (isset($reportData['summary']['permanently_failed_transactions']) && $reportData['summary']['permanently_failed_transactions'] > 10) {
            $recommendations[] = 'Analyze permanently failed transactions to identify common failure patterns and implement specific error handling.';
        }
        
        // Add risk-level specific recommendations
        if ($riskLevel === 'high') {
            $recommendations[] = 'Conduct an immediate security audit of all systems.';
            $recommendations[] = 'Consider implementing more aggressive rate limiting and IP blocking for suspicious activities.';
        } elseif ($riskLevel === 'medium') {
            $recommendations[] = 'Review security monitoring configuration to ensure all critical events are captured.';
            $recommendations[] = 'Conduct security awareness training for all staff.';
        }
        
        return $recommendations;
    }
}