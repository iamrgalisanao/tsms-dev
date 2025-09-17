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

    $activeTab = 'detailed';
    $summary = null; // populated by summary() route

    // return view('transactions.logs.index', compact('logs', 'providers', 'terminals', 'filters'));
     return view('transactions.logs.index', compact('logs', 'terminals', 'filters', 'activeTab', 'summary'));
    }

    public function show($id)
    {
        try {
            $transaction = Transaction::with([
                'terminal',
                'tenant',
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

    /**
     * Summary view: grouped roll-ups by date, tenant, and terminal using existing numeric fields.
     * Columns: date, tenant (trade_name), terminal (serial/machine), tx_count, gross, vat, net, refund
     */
    public function summary(Request $request)
    {
        $filters = $request->only([
            'status',
            'date_from',
            'date_to',
            'terminal_id',
        ]);

        $query = \DB::table('transactions as t')
            ->leftJoin('tenants as tn', 'tn.id', '=', 't.tenant_id')
            ->leftJoin('pos_terminals as term', 'term.id', '=', 't.terminal_id')
            ->when(isset($filters['status']), function ($q) use ($filters) {
                $q->where('t.validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function ($q) use ($filters) {
                $q->where('t.created_at', '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($q) use ($filters) {
                $q->where('t.created_at', '<=', $filters['date_to'] . ' 23:59:59');
            })
            ->when(isset($filters['terminal_id']), function ($q) use ($filters) {
                $q->where('t.terminal_id', $filters['terminal_id']);
            })
            ->selectRaw('DATE(t.created_at) as date')
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw('COALESCE(tn.trade_name, "Unknown") as trade_name')
            ->selectRaw('term.serial_number, term.machine_number')
            ->selectRaw('COUNT(*) as tx_count')
            ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross')
            ->selectRaw('COALESCE(SUM(t.vat_amount),0) as vat')
            ->selectRaw('COALESCE(SUM(t.net_sales),0) as net')
            ->selectRaw('COALESCE(SUM(t.refund_amount),0) as refund')
            ->groupBy('date', 't.tenant_id', 't.terminal_id', 'trade_name', 'term.serial_number', 'term.machine_number')
            ->orderBy('date', 'desc');

        $summary = $query->paginate(15);

        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);

        $activeTab = 'summary';
        $logs = collect(); // not needed on summary route

        if ($request->wantsJson()) {
            return response()->json($summary);
        }

        return view('transactions.logs.index', compact('logs', 'terminals', 'filters', 'activeTab', 'summary'));
    }
}