<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Services\SystemLogService;
use App\Services\LogExportService;
use Illuminate\Http\Request;

class LogViewerController extends Controller
{
    protected $logService;
    protected $exportService;

    public function __construct(SystemLogService $logService, LogExportService $exportService)
    {
        $this->logService = $logService;
        $this->exportService = $exportService;
    }

    public function index(Request $request)
    {
        $auditLogs = SystemLog::with(['user'])
            ->where('type', 'audit')
            ->latest()
            ->paginate(15);

        $webhookLogs = SystemLog::with(['terminal'])
            ->where('type', 'webhook')
            ->latest()
            ->paginate(15);

        $stats = $this->logService->getEnhancedStats();

        return view('logs.index', compact('auditLogs', 'webhookLogs', 'stats'));
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

    public function export(Request $request, string $format = 'csv')
    {
        return $this->exportService->export($format, $request->all());
    }
}