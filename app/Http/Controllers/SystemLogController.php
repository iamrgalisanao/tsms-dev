<?php

namespace App\Http\Controllers;

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
        $logs = SystemLog::with(['terminal', 'user', 'webhook'])
            ->when($request->filled('search'), function($q) use ($request) {
                $q->where('transaction_id', 'like', "%{$request->search}%");
            })
            ->when($request->filled('type'), function($q) use ($request) {
                $q->where('type', $request->type);
            })
            ->when($request->filled('severity'), function($q) use ($request) {
                $q->where('severity', $request->severity);
            })
            ->when($request->filled('user_id'), function($q) use ($request) {
                $q->where('user_id', $request->user_id);
            })
            ->when($request->filled('action'), function($q) use ($request) {
                $q->where('action', $request->action);
            })
            ->latest()
            ->paginate(15);

        $stats = $this->logService->getEnhancedStats();

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