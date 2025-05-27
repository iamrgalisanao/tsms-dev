<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemLog;
use App\Services\SystemLogExportService;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use App\Exports\SystemLogsExport;
use Maatwebsite\Excel\Facades\Excel;
use PDF;

class SystemLogController extends Controller
{
    protected $exportService;
    protected $logService;

    public function __construct(SystemLogExportService $exportService, SystemLogService $logService)
    {
        $this->exportService = $exportService;
        $this->logService = $logService;
    }

    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->where(function($q) {
                $q->where('log_type', 'system')
                  ->orWhere('action', 'auth.login_failed');
            });

        // Add filters
        if ($request->filled('type')) {
            $query->where('log_type', $request->type);
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }

        $logs = $query->latest()
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'system' => AuditLog::where('log_type', 'system')->count(),
            'errors' => AuditLog::where('severity', 'error')
                ->orWhere('action', 'auth.login_failed')
                ->count(),
            'success' => AuditLog::where('severity', 'info')->count(),
            'pending' => AuditLog::where('severity', 'pending')->count(),
        ];

        return view('dashboard.system-logs', compact('logs', 'stats'));
    }

    public function export(Request $request, string $format = 'csv')
    {
        return $this->exportService->export($format, $request->all());
    }

    public function getContext($id)
    {
        $log = SystemLog::findOrFail($id);
        return response()->json($log->context);
    }

    public function getFilteredLogs(Request $request)
    {
        $logs = $this->logService->getFilteredLogs($request->all());
        return response()->json($logs);
    }

    public function auditTrail(Request $request)
    {
        $logs = SystemLog::where('type', 'audit')
            ->with('user')
            ->latest()
            ->paginate(15);

        return view('dashboard.audit-trail', compact('logs'));
    }

    public function webhookLogs(Request $request)
    {
        $logs = SystemLog::where('type', 'webhook')
            ->with(['terminal'])
            ->latest()
            ->paginate(15);

        return view('dashboard.webhook-logs', compact('logs'));
    }
}