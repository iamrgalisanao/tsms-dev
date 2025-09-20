<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Models\Tenant;
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

        // Attach tenant trade_name to each audit log where possible without N+1 queries
        $logs = $auditLogs->getCollection();
        $tenantIds = [];
        foreach ($logs as $log) {
            // Prefer explicit metadata tenant_id (handle array or JSON string)
            $meta = $log->metadata ?? [];
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $meta = $decoded;
                } else {
                    $meta = [];
                }
            }
            if (is_array($meta) && !empty($meta['tenant_id']) && is_numeric($meta['tenant_id'])) {
                $tenantIds[(int) $meta['tenant_id']] = true;
                continue;
            }
            // Resource points to tenant
            if (($log->resource_type ?? null) === 'tenant' && is_numeric($log->resource_id)) {
                $tenantIds[(int) $log->resource_id] = true;
                continue;
            }
            // Auditable points to tenant
            if (($log->auditable_type ?? null) === 'tenant' && is_numeric($log->auditable_id)) {
                $tenantIds[(int) $log->auditable_id] = true;
            }
        }
        if (!empty($tenantIds)) {
            $tenantMap = Tenant::whereIn('id', array_keys($tenantIds))
                ->get(['id', 'trade_name'])
                ->keyBy('id');
            foreach ($logs as $log) {
                $tenantId = null;
                $meta = $log->metadata ?? [];
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $meta = $decoded;
                    } else {
                        $meta = [];
                    }
                }
                if (is_array($meta) && !empty($meta['tenant_id']) && is_numeric($meta['tenant_id'])) {
                    $tenantId = (int) $meta['tenant_id'];
                } elseif (($log->resource_type ?? null) === 'tenant' && is_numeric($log->resource_id)) {
                    $tenantId = (int) $log->resource_id;
                } elseif (($log->auditable_type ?? null) === 'tenant' && is_numeric($log->auditable_id)) {
                    $tenantId = (int) $log->auditable_id;
                }
                if ($tenantId && isset($tenantMap[$tenantId])) {
                    $log->setAttribute('tenant_name', $tenantMap[$tenantId]->trade_name ?? ('Tenant #'.$tenantId));
                } elseif ($tenantId) {
                    // Unknown tenant record, still show the ID
                    $log->setAttribute('tenant_name', 'Tenant #'.$tenantId);
                } else {
                    $log->setAttribute('tenant_name', null);
                }
            }
            // Ensure the paginator reflects our mutated collection
            $auditLogs->setCollection($logs);
        }

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

    // public function getAuditContext($id)
    // {
    //     try {
    //         $auditLog = \App\Models\AuditLog::with(['user'])->findOrFail($id);
            
    //         // Log the access to audit details for audit trail
    //         \App\Models\AuditLog::create([
    //             'user_id' => auth()->id(),
    //             'action' => 'audit_log.viewed',
    //             'action_type' => 'AUDIT_ACCESS',
    //             'resource_type' => 'audit_log',
    //             'resource_id' => $auditLog->id,
    //             'message' => 'Audit log details accessed',
    //             'ip_address' => request()->ip(),
    //             'metadata' => json_encode([
    //                 'accessed_audit_id' => $auditLog->id,
    //                 'original_action' => $auditLog->action,
    //                 'original_resource' => $auditLog->resource_type
    //             ])
    //         ]);

    //         return response()->json([
    //             'id' => $auditLog->id,
    //             'created_at' => $auditLog->created_at,
    //             'user' => $auditLog->user,
    //             'action' => $auditLog->action,
    //             'action_type' => $auditLog->action_type,
    //             'resource_type' => $auditLog->resource_type,
    //             'resource_id' => $auditLog->resource_id,
    //             'message' => $auditLog->message,
    //             'ip_address' => $auditLog->ip_address,
    //             'old_values' => $auditLog->old_values,
    //             'new_values' => $auditLog->new_values,
    //             'metadata' => $auditLog->metadata
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'error' => 'Failed to load audit context',
    //             'message' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    
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
            'metadata' => [
                'accessed_audit_id' => $auditLog->id,
                'original_action' => $auditLog->action,
                'original_resource' => $auditLog->resource_type
            ]
        ]);

        // Legacy data handling: ensure all fields are present and properly formatted
        $user = $auditLog->user;
        if (!$user) {
            $user = (object)[ 'name' => 'System' ];
        }
        $ip = $auditLog->ip_address ?? 'N/A';
        $oldValues = $auditLog->old_values ?? null;
        $newValues = $auditLog->new_values ?? null;
        $metadata = $auditLog->metadata ?? [];
        // If metadata is a string, try to decode
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        // Resolve tenant for context view
        $tenantId = null;
        $meta = $metadata;
        if (is_array($meta) && !empty($meta['tenant_id']) && is_numeric($meta['tenant_id'])) {
            $tenantId = (int) $meta['tenant_id'];
        } elseif (($auditLog->resource_type ?? null) === 'tenant' && is_numeric($auditLog->resource_id)) {
            $tenantId = (int) $auditLog->resource_id;
        } elseif (($auditLog->auditable_type ?? null) === 'tenant' && is_numeric($auditLog->auditable_id)) {
            $tenantId = (int) $auditLog->auditable_id;
        }
        $tenantPayload = null;
        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            $tenantPayload = [
                'id' => $tenantId,
                'trade_name' => $tenant->trade_name ?? null,
            ];
        }

        return response()->json([
            'id' => $auditLog->id,
            'created_at' => $auditLog->created_at,
            'user' => $user,
            'action' => $auditLog->action ?? 'N/A',
            'action_type' => $auditLog->action_type ?? 'N/A',
            'resource_type' => $auditLog->resource_type ?? 'N/A',
            'resource_id' => $auditLog->resource_id ?? 'N/A',
            'message' => $auditLog->message ?? '',
            'ip_address' => $ip,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'tenant' => $tenantPayload,
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