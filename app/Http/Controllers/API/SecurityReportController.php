<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Security\Contracts\SecurityReportingInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SecurityReportController extends Controller
{
    private SecurityReportingInterface $reportingService;

    public function __construct(SecurityReportingInterface $reportingService)
    {
        $this->reportingService = $reportingService;
    }

    /**
     * Get security reports list
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $filters = $request->only(['status', 'format', 'from_date', 'to_date']);

        try {
            $reports = $this->reportingService->getReportsList($tenantId, $filters);
            return response()->json([
                'status' => 'success',
                'data' => $reports
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve reports list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new security report
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'format' => 'required|in:html,pdf,csv',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'template_id' => 'nullable|integer|exists:security_report_templates,id',
            'event_type' => 'nullable|string',
            'severity' => 'nullable|in:info,warning,critical',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->user()->tenant_id ?? 1;
        $userId = $request->user()->id;
        $templateId = $request->input('template_id');
        $format = $request->input('format', 'html');
        $filters = $request->only([
            'name', 'from', 'to', 'event_type', 
            'severity', 'source_ip', 'user_id'
        ]);

        try {
            $reportId = $this->reportingService->generateReport(
                $tenantId, 
                $filters, 
                $format, 
                $templateId, 
                $userId
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Report generated successfully',
                'report_id' => $reportId
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific report
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id ?? 1;

        try {
            $report = $this->reportingService->getReport($id, $tenantId);
            
            if (!$report) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Report not found'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export a report
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function export(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        // $format = $request->input('format', 'pdf');

        try {
            $report = $this->reportingService->getReport($id, $tenantId);
            
            if (!$report) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Report not found'
                ], 404);
            }

            // For now, we just return the report data
            // In a real implementation, this would generate a PDF/CSV
            return response()->json([
                'status' => 'success',
                'data' => $report,
                'message' => "Export functionality will be implemented in Phase 3"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}