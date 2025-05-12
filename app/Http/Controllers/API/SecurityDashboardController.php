<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Security\Contracts\SecurityDashboardInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityDashboardController extends Controller
{
    private SecurityDashboardInterface $dashboardService;

    public function __construct(SecurityDashboardInterface $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get security dashboard overview
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1; // Default to 1 for testing
        $filters = $request->only(['from', 'to', 'event_type', 'severity']);

        try {
            $dashboardData = $this->dashboardService->getDashboardData($tenantId, $filters);
            return response()->json([
                'status' => 'success',
                'data' => $dashboardData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }    }

    /**
     * Get advanced visualization data
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function advancedVisualization(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $filters = $request->only(['from', 'to', 'visualizationType', 'groupBy']);
        
        try {
            $visualizationData = $this->dashboardService->getAdvancedVisualizationData(
                $tenantId, 
                $filters['visualizationType'] ?? 'threat_map',
                $filters
            );
            
            return response()->json([
                'status' => 'success',
                'data' => $visualizationData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve visualization data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get events summary
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventsSummary(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $filters = $request->only(['from', 'to', 'event_type', 'severity']);

        try {
            $summary = $this->dashboardService->getEventsSummary($tenantId, $filters);
            return response()->json([
                'status' => 'success',
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve events summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get alerts summary
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function alertsSummary(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $filters = $request->only(['from', 'to']);

        try {
            $summary = $this->dashboardService->getAlertsSummary($tenantId, $filters);
            return response()->json([
                'status' => 'success',
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve alerts summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get time series metrics
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeSeriesMetrics(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $metricType = $request->input('metric_type', 'events_by_hour');
        $params = $request->only(['from', 'to']);

        try {
            $metrics = $this->dashboardService->getTimeSeriesMetrics($tenantId, $metricType, $params);
            return response()->json([
                'status' => 'success',
                'data' => $metrics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve time series metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}