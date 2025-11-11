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
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

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
            'tenant_id',
            'terminal_id',
            'amount_min',
            'amount_max'
        ]);

        // Determine pagination size. If a date filter is applied and per_page is not explicitly set,
        // load a larger page (e.g., 1000) to reflect all transactions for that range (e.g., today's 289).
        $perPage = (int) $request->input('per_page', 15);
        if (($request->filled('date_from') || $request->filled('date_to')) && !$request->has('per_page')) {
            $perPage = 1000;
        }

        // Add transaction_id search handling
        if ($request->filled('transaction_id')) {
            $filters['transaction_id'] = trim($request->transaction_id);
        }

    // Allow 'transaction' as a date basis which uses the canonical transaction_timestamp
    $basis = in_array($request->input('date_basis'), ['created','completed','transaction']) ? $request->input('date_basis') : 'completed';
    $dateColumn = $basis === 'completed' ? 'completed_at' : ($basis === 'transaction' ? 'transaction_timestamp' : 'created_at');

        // Build select list conditionally so we don't attempt to select columns
        // that may not exist on older database schemas.
        $select = [
            'id',
            'transaction_id',
            'terminal_id',
            // canonical stored amounts used across the app/summary
            'gross_sales as amount',
            'vat_amount as vat',
            'net_sales',
            'refund_amount as refund',
            'vatable_sales',
            'sc_vat_exempt_sales',
            'validation_status',
            'transaction_timestamp',
            'created_at',
            'completed_at'
        ];

        // Include receipt_no when available in the schema so the Detailed view can render it
        if (Schema::hasColumn('transactions', 'receipt_no')) {
            $select[] = 'receipt_no';
        }

        // Add available discount fields
        if (Schema::hasColumn('transactions', 'promo_discount')) {
            $select[] = 'promo_discount';
        }
        if (Schema::hasColumn('transactions', 'senior_discount')) {
            $select[] = 'senior_discount';
        }
        if (Schema::hasColumn('transactions', 'pwd_discount')) {
            $select[] = 'pwd_discount';
        }
        
        // Add available service charge fields
        if (Schema::hasColumn('transactions', 'service_charge')) {
            $select[] = 'service_charge';
        }
        if (Schema::hasColumn('transactions', 'management_service_charge')) {
            $select[] = 'management_service_charge';
        }
        
        // Add available tax fields
        if (Schema::hasColumn('transactions', 'tax_exempt')) {
            $select[] = 'tax_exempt';
        }

        $logs = Transaction::select($select)
            ->with([
                'terminal:id,serial_number,tenant_id,machine_number',
                'terminal.tenant:id,trade_name',
                // Eager-load adjustments so the Detailed view can compute discounts
                // from child rows when denormalized columns are empty.
                'adjustments:transaction_pk,adjustment_type,amount'
            ])
            ->when(isset($filters['transaction_id']), function ($query) use ($filters) {
            $search = str_replace('TX-', '', $filters['transaction_id']);
            return $query->where('transaction_id', 'like', "%{$search}%");
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
            return $query->where('validation_status', $filters['status']);
            })
            // Default behavior: when the schema supports receipt_no and no
            // explicit status filter is provided, exclude non-VALID rows
            // (e.g., DUPLICATE) so UI/reporting matches POS-style unique-receipt
            // counts by default. If the receipt_no column is not present,
            // preserve legacy behavior (don't filter).
            ->when(Schema::hasColumn('transactions', 'receipt_no') && !isset($filters['status']), function ($query) {
                // Exclude only DUPLICATE sentinel rows by default so that
                // PENDING/ERROR/VALID rows are still visible to operators and
                // tests while removing duplicates from POS-style counts.
                return $query->where('validation_status', '!=', 'DUPLICATE');
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters, $dateColumn) {
                // Apply date filtering based on selected date basis.
                // For transaction_timestamp, use it as primary with created_at as fallback only for NULL values.
                if ($dateColumn === 'transaction_timestamp') {
                    $query->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            // Primary: transaction_timestamp is not null and within range
                            $subQ->whereNotNull('transaction_timestamp')
                                 ->where('transaction_timestamp', '>=', $filters['date_from'] . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($filters) {
                            // Fallback: transaction_timestamp is null, use created_at
                            $subQ->whereNull('transaction_timestamp')
                                 ->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
                        });
                    });
                } else {
                    $query->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
                }
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters, $dateColumn) {
                if ($dateColumn === 'transaction_timestamp') {
                    $query->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            // Primary: transaction_timestamp is not null and within range
                            $subQ->whereNotNull('transaction_timestamp')
                                 ->where('transaction_timestamp', '<=', $filters['date_to'] . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($filters) {
                            // Fallback: transaction_timestamp is null, use created_at
                            $subQ->whereNull('transaction_timestamp')
                                 ->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
                        });
                    });
                } else {
                    $query->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
                }
            })
            ->when(isset($filters['tenant_id']), function ($query) use ($filters) {
            return $query->where('tenant_id', $filters['tenant_id']);
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
            ->orderBy($dateColumn, 'desc')
            ->paginate($perPage)
            ->appends($request->all());

        if ($request->wantsJson()) {
            return response()->json($logs);
        }

        // $providers = PosProvider::all();
        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);

        $tenants = Tenant::orderBy('trade_name')->get(['id','trade_name']);

    $activeTab = 'detailed';
    $summary = null; // populated by summary() route

    // return view('transactions.logs.index', compact('logs', 'providers', 'terminals', 'filters'));
         return view('transactions.logs.index', compact('logs', 'terminals', 'tenants', 'filters', 'activeTab', 'summary'));
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
            'tenant_id',
            'terminal_id',
        ]);

        // Allow 'transaction' as a date basis for summaries as well. When selected,
        // group by the canonical transaction timestamp but fall back to created_at
        // for rows that don't have transaction_timestamp set.
        $basis = in_array($request->input('date_basis'), ['created','completed','transaction']) ? $request->input('date_basis') : 'completed';
        if ($basis === 'completed') {
            $dateColumn = 't.completed_at';
            $dateExpr = 't.completed_at';
        } elseif ($basis === 'transaction') {
            $dateColumn = 't.transaction_timestamp';
            // Use COALESCE so that rows without transaction_timestamp will still
            // be included using created_at as a fallback for grouping and ordering.
            $dateExpr = 'COALESCE(t.transaction_timestamp, t.created_at)';
        } else {
            $dateColumn = 't.created_at';
            $dateExpr = 't.created_at';
        }

        // Determine pagination size like index(): if date filter provided and no per_page set, use 1000
        $perPage = (int) $request->input('per_page', 15);
        if (($request->filled('date_from') || $request->filled('date_to')) && !$request->has('per_page')) {
            $perPage = 1000;
        }

        // For summary roll-ups allow grouping/filters by transaction_timestamp as well
        $query = \DB::table('transactions as t')
            ->leftJoin('tenants as tn', 'tn.id', '=', 't.tenant_id')
            ->leftJoin('pos_terminals as term', 'term.id', '=', 't.terminal_id')
            ->when(isset($filters['status']), function ($q) use ($filters) {
                $q->where('t.validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function ($q) use ($filters, $dateColumn) {
                // Apply date filtering based on selected date basis.
                // For transaction_timestamp, use it as primary with created_at as fallback only for NULL values.
                if ($dateColumn === 't.transaction_timestamp') {
                    $q->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            // Primary: transaction_timestamp is not null and within range
                            $subQ->whereNotNull('t.transaction_timestamp')
                                 ->where('t.transaction_timestamp', '>=', $filters['date_from'] . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($filters) {
                            // Fallback: transaction_timestamp is null, use created_at
                            $subQ->whereNull('t.transaction_timestamp')
                                 ->where('t.created_at', '>=', $filters['date_from'] . ' 00:00:00');
                        });
                    });
                } else {
                    $q->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
                }
            })
            ->when(isset($filters['date_to']), function ($q) use ($filters, $dateColumn) {
                if ($dateColumn === 't.transaction_timestamp') {
                    $q->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            // Primary: transaction_timestamp is not null and within range
                            $subQ->whereNotNull('t.transaction_timestamp')
                                 ->where('t.transaction_timestamp', '<=', $filters['date_to'] . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($filters) {
                            // Fallback: transaction_timestamp is null, use created_at
                            $subQ->whereNull('t.transaction_timestamp')
                                 ->where('t.created_at', '<=', $filters['date_to'] . ' 23:59:59');
                        });
                    });
                } else {
                    $q->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
                }
            })
            ->when(isset($filters['tenant_id']), function ($q) use ($filters) {
                $q->where('t.tenant_id', $filters['tenant_id']);
            })
            ->when(isset($filters['terminal_id']), function ($q) use ($filters) {
                $q->where('t.terminal_id', $filters['terminal_id']);
            })
            ->selectRaw('DATE(' . $dateExpr . ') as date')
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw('COALESCE(tn.trade_name, "Unknown") as trade_name')
            ->selectRaw('term.serial_number, term.machine_number')
            ->selectRaw('COUNT(*) as tx_count')
            // If receipt_no exists, also surface unique receipt counts so the
            // UI can present provider-style counts (COUNT DISTINCT receipt_no).
            ->when(Schema::hasColumn('transactions', 'receipt_no'), function ($q) {
                // NULLIF guards against empty-string receipt_no values being counted
                // as distinct; treat empty strings as NULL so they are excluded.
                $q->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts");
            })
            // Use stored gross_sales as the canonical gross for summary so it matches
            // the Detailed view and POS Z-reading totals.
            ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross')
            ->selectRaw('COALESCE(SUM(t.vat_amount),0) as vat')
            ->selectRaw('COALESCE(SUM(t.net_sales),0) as net')
            ->selectRaw('COALESCE(SUM(t.refund_amount),0) as refund')
            // Add available discount fields
            ->when(Schema::hasColumn('transactions', 'promo_discount'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.promo_discount),0) as promo_discount');
            })
            ->when(Schema::hasColumn('transactions', 'senior_discount'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.senior_discount),0) as senior_discount');
            })
            ->when(Schema::hasColumn('transactions', 'pwd_discount'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.pwd_discount),0) as pwd_discount');
            })
            // Add available service charge fields
            ->when(Schema::hasColumn('transactions', 'service_charge'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.service_charge),0) as service_charge');
            })
            ->when(Schema::hasColumn('transactions', 'management_service_charge'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.management_service_charge),0) as management_service_charge');
            })
            // Add available tax fields
            ->when(Schema::hasColumn('transactions', 'tax_exempt'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.tax_exempt),0) as tax_exempt');
            })
            ->when(Schema::hasColumn('transactions', 'vatable_sales'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.vatable_sales),0) as vatable_sales');
            })
            ->when(Schema::hasColumn('transactions', 'sc_vat_exempt_sales'), function ($q) {
                $q->selectRaw('COALESCE(SUM(t.sc_vat_exempt_sales),0) as sc_vat_exempt_sales');
            })
            ->selectRaw('MIN(t.id) as sample_tx_id')
            ->groupBy('date', 't.tenant_id', 't.terminal_id', 'trade_name', 'term.serial_number', 'term.machine_number')
            ->orderBy('date', 'desc');

    // When the schema supports receipt_no, default summary roll-ups to VALID
    // transactions so aggregates align with POS-style unique receipt counts.
    if (Schema::hasColumn('transactions', 'receipt_no') && !isset($filters['status'])) {
        // Exclude only DUPLICATE sentinel rows by default for summaries as well.
        $query->where('t.validation_status', '!=', 'DUPLICATE');
    }

    $summary = $query->paginate($perPage)->appends($request->all());

        // Fetch one representative transaction per summary row to display full payload details
        $sampleIds = collect($summary->items())->pluck('sample_tx_id')->filter()->unique()->values()->all();
        $sampleTransactions = [];
        if (!empty($sampleIds)) {
            $sampleTransactions = Transaction::with(['adjustments','taxes','terminal','tenant'])
                ->whereIn('id', $sampleIds)
                ->get()
                ->keyBy('id');
        }

        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);
        $tenants = Tenant::orderBy('trade_name')->get(['id','trade_name']);

        $activeTab = 'summary';
        $logs = collect(); // not needed on summary route

        if ($request->wantsJson()) {
            return response()->json($summary);
        }

    return view('transactions.logs.index', compact('logs', 'terminals', 'tenants', 'filters', 'activeTab', 'summary', 'sampleTransactions'));
    }
}