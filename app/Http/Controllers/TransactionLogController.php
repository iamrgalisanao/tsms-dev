<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\TransactionLogService;
use Illuminate\Support\Facades\Gate;

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
        
        return $this->logService->export($request->only([
            'validation_status',
            'job_status',
            'date_from',
            'date_to'
        ]));
    }
}