<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SecurityReport;
use App\Services\Security\Contracts\SecurityReportingInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class SecurityReportController extends Controller
{
    /**
     * Common validation rules
     */
    private const RULE_NULLABLE_ARRAY = 'nullable|array';
    private const RULE_REQUIRED_STRING = 'required|string|max:255';
    private const RULE_NULLABLE_STRING = 'nullable|string';

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
        }    }

    /**
     * Export a report
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */    public function export(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $format = $request->input('format', 'pdf');

        try {
            // Get the report model instead of just the array data
            $report = SecurityReport::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->first();
            
            if (!$report) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Report not found'
                ], 404);
            }
            
            // Generate the export file
            $exportedFile = $this->reportingService->exportReport($report, $format);
            
            if (!$exportedFile || !file_exists($exportedFile)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to generate export file'
                ], 500);
            }

            $contentType = $this->getContentTypeForFormat($format);
            $fileName = basename($exportedFile);

            return response()->download(
                $exportedFile,
                $fileName,
                [
                    'Content-Type' => $contentType,
                    'Content-Disposition' => 'attachment; filename="' . $fileName . '"'
                ]
            )->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get a specific template
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTemplate(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id ?? 1;

        try {
            $template = $this->reportingService->getReportTemplate($id, $tenantId);
            
            if (!$template) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Template not found'
                ], 404);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $template
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get templates list
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTemplates(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $filters = $request->only(['type', 'is_scheduled', 'is_system']);

        try {
            $templates = $this->reportingService->getReportTemplates($tenantId, $filters);
            return response()->json([
                'status' => 'success',
                'data' => $templates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve templates list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new template
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeTemplate(Request $request)
    {        $validator = Validator::make($request->all(), [
            'name' => self::RULE_REQUIRED_STRING,
            'description' => self::RULE_NULLABLE_STRING,
            'type' => 'required|string|in:security_events,failed_transactions,circuit_breaker_trips,login_attempts,security_alerts,comprehensive',
            'filters' => self::RULE_NULLABLE_ARRAY,
            'columns' => self::RULE_NULLABLE_ARRAY,
            'format' => 'nullable|string|in:html,pdf,csv,json',
            'is_scheduled' => 'nullable|boolean',
            'schedule_frequency' => 'nullable|string|in:daily,weekly,monthly',
            'notification_settings' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $tenantId = $request->user()->tenant_id ?? 1;
        
        // Prepare template data
        $templateData = $request->only([
            'name',
            'description',
            'type',
            'filters',
            'columns',
            'format',
            'is_scheduled',
            'schedule_frequency',
            'notification_settings'
        ]);

        try {
            $templateId = $this->reportingService->createReportTemplate($tenantId, $templateData);
            return response()->json([
                'status' => 'success',
                'message' => 'Template created successfully',
                'template_id' => $templateId
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the content type for a given export format
     *
     * @param string $format
     * @return string
     */
    private function getContentTypeForFormat(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            'json' => 'application/json',
            default => 'text/html',
        };
    }
}