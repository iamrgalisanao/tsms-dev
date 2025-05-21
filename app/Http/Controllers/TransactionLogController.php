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
        Gate::authorize('view-transaction-logs');
        
        return view('transaction-logs.index', [
            'logs' => $this->logService->getPaginatedLogs($request->all())
        ]);
    }

    public function show($id)
    {
        Gate::authorize('view-transaction-logs');
        
        return view('transaction-logs.show', [
            'log' => $this->logService->getLogDetail($id)
        ]);
    }

    public function export(Request $request)
    {
        Gate::authorize('export-transaction-logs');
        
        return $this->logService->exportLogs($request->all());
    }
}