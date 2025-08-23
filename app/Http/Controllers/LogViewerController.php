<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Services\SystemLogService;
use App\Services\LogExportService;
use Illuminate\Http\Request;

class LogViewerController extends Controller
{
    // ...existing code...
    protected $logService;
    protected $exportService;

    public function __construct(SystemLogService $logService, LogExportService $exportService)
    {
        $this->logService = $logService;
        $this->exportService = $exportService;
    }

    public function index(Request $request)
    {
        $auditLogs = \App\Models\AuditLog::with(['user'])
            ->when($request->filled('action_type'), fn($q) => $q->where('action_type', $request->action_type))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('resource_type'), fn($q) => $q->where('resource_type', $request->resource_type))
            ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest('created_at')
            ->paginate(25);

        $webhookLogs = SystemLog::with(['terminal'])
            ->where('type', 'webhook')
            ->latest()
            ->paginate(15);

        $stats = $this->logService->getEnhancedStats();
        
        // Add audit-specific stats
        $auditStats = [
            'total_audit_logs' => \App\Models\AuditLog::count(),
            'auth_events' => \App\Models\AuditLog::where('action_type', 'AUTH')->count(),
            'transaction_events' => \App\Models\AuditLog::whereIn('action_type', [
                'TRANSACTION_RECEIVED', 'TRANSACTION_VOID_POS', 'TRANSACTION_PROCESSED'
            ])->count(),
            'data_changes' => \App\Models\AuditLog::whereNotNull('old_values')->count(),
            'system_events' => \App\Models\AuditLog::where('action_type', 'SYSTEM')->count(),
        ];

        return view('logs.index', compact('auditLogs', 'webhookLogs', 'stats', 'auditStats'));
    }

    public function getFilteredLogs(Request $request)
    {
        return $this->logService->getFilteredLogs($request->all());
    }

    public function getContext($id)
    {
        $log = SystemLog::findOrFail($id);
        return response()->json($log->context);
    }

    /**
     * Return system log details for modal AJAX.
     */
    public function systemContext($id)
    {
        $log = \App\Models\SystemLog::findOrFail($id);
        // Optionally format context as JSON string if needed
        $log->context = is_array($log->context) ? json_encode($log->context) : $log->context;
        return response()->json($log);
    }

    public function getAuditContext($id)
    {
        try {
            $auditLog = \App\Models\AuditLog::with(['user'])->findOrFail($id);
            
            // Log the access to audit details for audit trail
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'audit_log.viewed',
                'action_type' => 'AUDIT_ACCESS',
                'resource_type' => 'audit_log',
                'resource_id' => $auditLog->id,
                'message' => 'Audit log details accessed',
                'ip_address' => request()->ip(),
                'metadata' => json_encode([
                    'accessed_audit_id' => $auditLog->id,
                    'original_action' => $auditLog->action,
                    'original_resource' => $auditLog->resource_type
                ])
            ]);

            return response()->json([
                'id' => $auditLog->id,
                'created_at' => $auditLog->created_at,
                'user' => $auditLog->user,
                'action' => $auditLog->action,
                'action_type' => $auditLog->action_type,
                'resource_type' => $auditLog->resource_type,
                'resource_id' => $auditLog->resource_id,
                'message' => $auditLog->message,
                'ip_address' => $auditLog->ip_address,
                'old_values' => $auditLog->old_values,
                'new_values' => $auditLog->new_values,
                'metadata' => $auditLog->metadata
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load audit context',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function export(Request $request, string $format = 'csv')
    {
        return $this->exportService->export($format, $request->all());
    }
}