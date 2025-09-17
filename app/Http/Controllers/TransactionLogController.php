<?php

namespace App\Http\Controllers;

use App\Events\TransactionLogUpdated;
use App\Exports\TransactionLogsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\TransactionLogService;
use App\Services\TransactionDetailService;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\PosProvider;
use App\Models\PosTerminal;

class TransactionLogController extends Controller
{
    protected $logService;
    protected $detailService;

    public function __construct(TransactionLogService $logService, TransactionDetailService $detailService)
    {
        $this->logService = $logService;
        $this->detailService = $detailService;
    }

    public function index(Request $request)
    {
        $filters = $request->only([
            'status',
            'date_from',
            'date_to',
            'terminal_id',
            'amount_min',
            'amount_max'
        ]);

        // Add transaction_id search handling
        if ($request->filled('transaction_id')) {
            $filters['transaction_id'] = trim($request->transaction_id);
        }

        $logs = Transaction::select([
            'id',
            'transaction_id',
            'terminal_id',
            'gross_sales as amount',
            'validation_status',
            'created_at'
            ])
            ->with(['terminal:id,serial_number,tenant_id,machine_number', 'terminal.tenant:id,trade_name'])
            ->when(isset($filters['transaction_id']), function ($query) use ($filters) {
            $search = str_replace('TX-', '', $filters['transaction_id']);
            return $query->where('transaction_id', 'like', "%{$search}%");
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
            return $query->where('validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters) {
            return $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters) {
            return $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
            })
            ->when(isset($filters['terminal_id']), function ($query) use ($filters) {
            return $query->where('terminal_id', $filters['terminal_id']);
            })
            ->when(isset($filters['amount_min']), function ($query) use ($filters) {
            return $query->where('gross_sales', '>=', $filters['amount_min']);
            })
            ->when(isset($filters['amount_max']), function ($query) use ($filters) {
            return $query->where('gross_sales', '<=', $filters['amount_max']);
            })
            ->latest()
            ->paginate(15);

        if ($request->wantsJson()) {
            return response()->json($logs);
        }

        // $providers = PosProvider::all();
        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);

        // return view('transactions.logs.index', compact('logs', 'providers', 'terminals', 'filters'));
         return view('transactions.logs.index', compact('logs', 'terminals', 'filters'));
    }

    public function show($id)
    {
        try {
            $transaction = Transaction::with([
                'terminal',
                'tenant',
                'processingHistory',
                'adjustments',
                'taxes'
            ])->findOrFail($id);

            if (!$transaction) {
                return redirect()
                    ->route('transactions.logs.index')
                    ->with('error', 'Transaction not found');
            }
            
            return view('transactions.logs.show', [
                'transaction' => $transaction,
                'metrics' => $this->detailService->getDetailedMetrics($transaction),
                'timeline' => $this->detailService->getProcessingTimeline($transaction)
            ]);
        } catch (\Exception $e) {
            return redirect()
                ->route('transactions.logs.index')
                ->with('error', 'Error loading transaction: ' . $e->getMessage());
        }
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