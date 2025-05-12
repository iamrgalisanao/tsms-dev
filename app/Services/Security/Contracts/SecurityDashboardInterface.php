<?php

namespace App\Services\Security\Contracts;

interface SecurityDashboardInterface
{
    /**
     * Get security dashboard data
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getDashboardData(int $tenantId, array $filters = []): array;

    /**
     * Get security events summary
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getEventsSummary(int $tenantId, array $filters = []): array;

    /**
     * Get security alerts summary
     * 
     * @param int $tenantId
     * @param array $filters
     * @return array
     */
    public function getAlertsSummary(int $tenantId, array $filters = []): array;    /**
     * Get security metrics over time
     * 
     * @param int $tenantId
     * @param string $metricType
     * @param array $params
     * @return array
     */
    public function getTimeSeriesMetrics(int $tenantId, string $metricType, array $params = []): array;
    
    /**
     * Get advanced visualization data
     * 
     * @param int $tenantId
     * @param string $visualizationType
     * @param array $params
     * @return array
     */
    public function getAdvancedVisualizationData(int $tenantId, string $visualizationType, array $params = []): array;
}