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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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

        $basis = in_array($request->input('date_basis'), ['created','completed']) ? $request->input('date_basis') : 'completed';
        $dateColumn = $basis === 'completed' ? 'completed_at' : 'created_at';

        $logs = Transaction::select([
            'id',
            'transaction_id',
            'terminal_id',
            'gross_sales as amount',
            'validation_status',
            'created_at',
            'completed_at'
            ])
            ->with(['terminal:id,serial_number,tenant_id,machine_number', 'terminal.tenant:id,trade_name'])
            ->when(isset($filters['transaction_id']), function ($query) use ($filters) {
            $search = str_replace('TX-', '', $filters['transaction_id']);
            return $query->where('transaction_id', 'like', "%{$search}%");
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
            return $query->where('validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function ($query) use ($filters, $dateColumn) {
            return $query->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters, $dateColumn) {
            return $query->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
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

    public function show(Request $request, $id)
    {
        try {
            $transaction = Transaction::with([
                'terminal.tenant',
                'terminal.provider',
                'tenant',
                'adjustments',
                'taxes',
                'jobs',
                'validations',
                'submission'
            ])->findOrFail($id);

            if ($request->wantsJson()) {
                return response()->json([
                    'id' => $transaction->id,
                    'transaction_id' => $transaction->transaction_id,
                    'receipt_no' => $transaction->receipt_no ?? 'N/A',
                    'amount' => (float) ($transaction->gross_sales ?? 0),
                    'net_sales' => (float) ($transaction->net_sales ?? 0),
                    'validation_status' => $transaction->validation_status,
                    'is_voided' => (bool) ($transaction->isVoided() ?? false),
                    'voided_at' => $transaction->voided_at,
                    'void_reason' => $transaction->void_reason,
                    'is_refunded' => (bool) ($transaction->is_refunded ?? false),
                    'refund_amount' => (float) ($transaction->refund_amount ?? 0),
                    'refund_reason' => $transaction->refund_reason ?? null,
                    'job_attempts' => (int) ($transaction->job_attempts ?? 0),
                    'created_at' => $transaction->created_at,
                    'completed_at' => $transaction->completed_at,
                    'terminal' => [
                        'id' => $transaction->terminal->id ?? null,
                        'serial_number' => $transaction->terminal->serial_number ?? 'N/A',
                        'tenant_id' => $transaction->terminal->tenant_id ?? null,
                        'machine_number' => $transaction->terminal->machine_number ?? null,
                        'tenant' => [
                            'id' => $transaction->terminal->tenant->id ?? null,
                            'trade_name' => $transaction->terminal->tenant->trade_name ?? 'N/A',
                        ],
                        'provider' => [
                            'id' => $transaction->terminal->provider->id ?? null,
                            'name' => $transaction->terminal->provider->name ?? 'N/A',
                        ],
                    ],
                    'tenant' => $transaction->tenant ?: ($transaction->terminal->tenant ?? null),
                    'adjustments' => $transaction->adjustments,
                    'taxes' => $transaction->taxes,
                    'payload' => $transaction->original_payload ? json_decode($transaction->original_payload) : null,
                    'retry_history' => $transaction->jobs->map(function ($job) {
                        return [
                            'attempt' => $job->attempts ?? 1,
                            'status' => $job->job_status,
                            'attempted_at' => $job->created_at,
                            'error' => $job->last_error,
                        ];
                    })->values(),
                    'submission_events' => $transaction->validations->map(function ($validation) {
                        return [
                            'submission_uuid' => $validation->id,
                            'status' => $validation->status_code ?? 'VALIDATED',
                            'created_at' => $validation->validated_at ?? $validation->created_at,
                        ];
                    })->values(),
                    'horizon_job_tags' => [
                        'transaction:' . $transaction->transaction_id,
                        'terminal:' . ($transaction->terminal->serial_number ?? 'unknown'),
                    ],
                ]);
            }
            
            return view('transactions.logs.show', [
                'transaction' => $transaction,
                'metrics' => $this->detailService->getDetailedMetrics($transaction),
                'timeline' => $this->detailService->getProcessingTimeline($transaction)
            ]);
        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json(['error' => $e->getMessage()], 404);
            }

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

    public function terminals()
    {
        return response()->json(
            PosTerminal::with('tenant:id,trade_name')
                ->get(['id', 'serial_number', 'tenant_id', 'machine_number'])
        );
    }

    public function tenants()
    {
        return response()->json(
            Tenant::orderBy('trade_name')->get(['id', 'trade_name'])
        );
    }

    /**
     * Return a server-side count of transactions with validation issues using
     * the same lightweight filters as the detailed logs view.
     */
    public function issuesCount(Request $request)
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

        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction'], true)
            ? $request->input('date_basis')
            : 'completed';

        $dateColumn = match ($basis) {
            'created' => 'created_at',
            'transaction' => 'transaction_timestamp',
            default => 'completed_at',
        };

        $query = Transaction::query();

        if ($request->filled('transaction_id')) {
            $search = str_replace('TX-', '', trim($request->transaction_id));
            $query->where('transaction_id', 'like', "%{$search}%");
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'VOIDED') {
                $query->whereNotNull('voided_at');
            } elseif ($filters['status'] === 'REFUNDED') {
                $query->where('is_refunded', true);
            } else {
                $query->where('validation_status', $filters['status']);
            }
        }

        if (isset($filters['date_from'])) {
            if ($dateColumn === 'transaction_timestamp') {
                $query->where(function ($q) use ($filters) {
                    $q->where(function ($subQ) use ($filters) {
                        $subQ->whereNotNull('transaction_timestamp')
                            ->where('transaction_timestamp', '>=', $filters['date_from'] . ' 00:00:00');
                    })->orWhere(function ($subQ) use ($filters) {
                        $subQ->whereNull('transaction_timestamp')
                            ->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
                    });
                });
            } else {
                $query->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
            }
        }

        if (isset($filters['date_to'])) {
            if ($dateColumn === 'transaction_timestamp') {
                $query->where(function ($q) use ($filters) {
                    $q->where(function ($subQ) use ($filters) {
                        $subQ->whereNotNull('transaction_timestamp')
                            ->where('transaction_timestamp', '<=', $filters['date_to'] . ' 23:59:59');
                    })->orWhere(function ($subQ) use ($filters) {
                        $subQ->whereNull('transaction_timestamp')
                            ->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
                    });
                });
            } else {
                $query->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
            }
        }

        if (isset($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (isset($filters['terminal_id'])) {
            $query->where('terminal_id', $filters['terminal_id']);
        }

        if (isset($filters['amount_min'])) {
            $query->where('gross_sales', '>=', $filters['amount_min']);
        }

        if (isset($filters['amount_max'])) {
            $query->where('gross_sales', '<=', $filters['amount_max']);
        }

        $count = $query->where('validation_status', 'WITH_ISSUES')->count();

        return response()->json(['count' => (int) $count]);
    }

    /**
     * Trigger manual reconciliation for stranded accepted intakes and missing
     * processed transactions. Available to admin, finance, and commercial users.
     */
    public function reconcile(Request $request)
    {
        $user = $request->user();

        try {
            Log::info('Manual reconciliation triggered by user', [
                'user_id' => $user?->id,
                'email' => $user?->email,
                'role' => $user?->role ?? null,
            ]);

            Artisan::call('tsms:reconcile-intake');
            $strandedOutput = trim(Artisan::output());

            Artisan::call('tsms:reconcile-intake', [
                '--repair-missing' => true,
            ]);
            $repairOutput = trim(Artisan::output());

            try {
                \App\Models\AuditLog::create([
                    'user_id' => $user?->id,
                    'ip_address' => $request->ip(),
                    'action' => 'MANUAL_RECONCILIATION_TRIGGERED',
                    'action_type' => 'reconciliation',
                    'resource_type' => 'transaction_intake',
                    'resource_id' => 'all',
                    'auditable_type' => 'system',
                    'auditable_id' => null,
                    'message' => 'Manual intake/transaction reconciliation triggered by ' . ($user?->email ?? 'unknown user'),
                    'metadata' => [
                        'stranded_output' => $strandedOutput,
                        'repair_output' => $repairOutput,
                    ],
                ]);
            } catch (\Throwable $auditEx) {
                Log::error('Failed to write manual reconciliation AuditLog', [
                    'error' => $auditEx->getMessage(),
                ]);
            }

            $message = 'Reconciliation completed successfully.';
            if ($strandedOutput) {
                $message .= "\n" . $strandedOutput;
            }
            if ($repairOutput) {
                $message .= "\n" . $repairOutput;
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'details' => [
                    'stranded' => $strandedOutput,
                    'repair' => $repairOutput,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual reconciliation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Manual reconciliation failed: ' . $e->getMessage(),
            ], 500);
        }
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

        $basis = in_array($request->input('date_basis'), ['created','completed']) ? $request->input('date_basis') : 'completed';
        $dateColumn = $basis === 'completed' ? 't.completed_at' : 't.created_at';

        // Determine pagination size like index(): if date filter provided and no per_page set, use 1000
        $perPage = (int) $request->input('per_page', 15);
        if (($request->filled('date_from') || $request->filled('date_to')) && !$request->has('per_page')) {
            $perPage = 1000;
        }

        $query = \DB::table('transactions as t')
            ->leftJoin('tenants as tn', 'tn.id', '=', 't.tenant_id')
            ->leftJoin('pos_terminals as term', 'term.id', '=', 't.terminal_id')
            ->when(isset($filters['status']), function ($q) use ($filters) {
                $q->where('t.validation_status', $filters['status']);
            })
            ->when(isset($filters['date_from']), function ($q) use ($filters, $dateColumn) {
                $q->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($q) use ($filters, $dateColumn) {
                $q->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
            })
            ->when(isset($filters['tenant_id']), function ($q) use ($filters) {
                $q->where('t.tenant_id', $filters['tenant_id']);
            })
            ->when(isset($filters['terminal_id']), function ($q) use ($filters) {
                $q->where('t.terminal_id', $filters['terminal_id']);
            })
            ->selectRaw('DATE(' . $dateColumn . ') as date')
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

    $summary = $query->paginate($perPage)->appends($request->all());

        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);
        $tenants = Tenant::orderBy('trade_name')->get(['id','trade_name']);

        $activeTab = 'summary';
        $logs = collect(); // not needed on summary route

        if ($request->wantsJson()) {
            return response()->json($summary);
        }

        return view('transactions.logs.index', compact('logs', 'terminals', 'tenants', 'filters', 'activeTab', 'summary'));
    }
}
