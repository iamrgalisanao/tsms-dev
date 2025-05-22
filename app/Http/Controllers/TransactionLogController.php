<?php

namespace App\Http\Controllers;

use App\Events\TransactionLogUpdated;
use App\Exports\TransactionLogsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\TransactionLogService;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class TransactionLogController extends Controller
{
    protected $logService;

    public function __construct(TransactionLogService $logService)
    {
        $this->logService = $logService;
    }

    public function index(Request $request)
    {
        $logs = $this->logService->getPaginatedLogs(
            $request->only(['status', 'date', 'terminal_id'])
        );

        if ($request->wantsJson()) {
            return response()->json($logs);
        }

        return view('transactions.logs.index', compact('logs'));
    }

    public function show($id)
    {
        $log = $this->logService->getLogWithHistory($id);
        return view('transactions.logs.show', compact('log'));
    }

    public function export(Request $request)
    {
        Gate::authorize('export-transaction-logs');
        
        $filename = 'transaction-logs-' . now()->format('Y-m-d') . '.xlsx';
        return Excel::download(new TransactionLogsExport($request->all()), $filename);
    }

    public function getUpdates(Request $request)
    {
        $lastId = $request->input('last_id');
        $updates = $this->logService->getUpdatesAfter($lastId);
        
        if ($request->wantsJson()) {
            return response()->json($updates);
        }
        
        return view('transactions.logs.partials.rows', compact('updates'));
    }
}