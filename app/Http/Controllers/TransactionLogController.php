<?php

namespace App\Http\Controllers;

use App\Events\TransactionLogUpdated;
use App\Exports\TransactionLogsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\TransactionLogService;
use App\Services\TransactionDetailService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class TransactionLogController extends Controller
{
    protected $logService;
    protected $detailService;
    protected $financeService;

    public function __construct(
        TransactionLogService $logService,
        TransactionDetailService $detailService,
        \App\Services\Reports\FinanceCalculationService $financeService
    ) {
        $this->logService = $logService;
        $this->detailService = $detailService;
        $this->financeService = $financeService;
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
        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction']) ? $request->input('date_basis') : 'transaction';
        $dateColumn = $basis === 'completed' ? 'completed_at' : ($basis === 'transaction' ? 'transaction_timestamp' : 'created_at');

        // Allow callers (web UI/API) to control sort direction for the
        // primary date column. Default remains DESC for backwards
        // compatibility, but ASC can be requested via sort_direction=asc.
        $sortDirection = strtolower($request->input('sort_direction')) === 'asc' ? 'asc' : 'desc';

        // Build select list conditionally so we don't attempt to select columns
        // that may not exist on older database schemas.
        $select = [
            'id',
            'transaction_id',
            'terminal_id',
            // canonical stored amounts used across the app/summary
            'gross_sales',
            'net_sales',
            'vat_amount',
            'refund_amount',
            'vatable_sales',
            'sc_vat_exempt_sales',
            'validation_status',
            'job_attempts',
            'transaction_timestamp',
            'original_payload',
            'voided_at',
            'void_reason',
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
                'adjustments:transaction_pk,adjustment_type,amount',
                'taxes:transaction_pk,tax_type,amount'
            ])
            // Unified search: allow the primary search box to match by
            // transaction ID, receipt number, tenant trade name, or
            // terminal identifiers.
            ->when(isset($filters['transaction_id']), function ($query) use ($filters) {
                $search = str_replace('TX-', '', trim($filters['transaction_id']));

                $query->where(function ($q) use ($search) {
                    $q->where('transaction_id', 'like', "%{$search}%");

                    // Optional: search by receipt_no when the column exists
                    if (Schema::hasColumn('transactions', 'receipt_no')) {
                        $q->orWhere('receipt_no', 'like', "%{$search}%");
                    }

                    // Match by terminal identifiers
                    $q->orWhereHas('terminal', function ($terminalQuery) use ($search) {
                        $terminalQuery
                            ->where('serial_number', 'like', "%{$search}%")
                            ->orWhere('machine_number', 'like', "%{$search}%");
                    });

                    // Match by tenant trade name (via direct tenant relation)
                    $q->orWhereHas('tenant', function ($tenantQuery) use ($search) {
                        $tenantQuery->where('trade_name', 'like', "%{$search}%");
                    });
                });
            })
            ->when(isset($filters['status']), function ($query) use ($filters) {
                if ($filters['status'] === 'VOIDED') {
                    return $query->whereNotNull('voided_at');
                }
                if ($filters['status'] === 'REFUNDED') {
                    return $query->where('is_refunded', true);
                }
                return $query->where('validation_status', $filters['status']);
            })
            // [FIX-FINANCE-RECON] Default: Exclude only DUPLICATE sentinel rows by default 
            // for general detailed view, but keep VOIDED transactions visible for audit.
            ->when(Schema::hasColumn('transactions', 'receipt_no') && !isset($filters['status']), function ($query) {
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
                // [FIX-FINANCE-RECON] Resilient filtering: match by direct tenant_id 
                // OR by the tenant of the linked terminal.
                return $query->where(function ($q) use ($filters) {
                    $q->where('tenant_id', $filters['tenant_id'])
                      ->orWhereHas('terminal', function ($sub) use ($filters) {
                          $sub->where('tenant_id', $filters['tenant_id']);
                      });
                });
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
            ->orderBy($dateColumn, $sortDirection)
            ->paginate($perPage)
            ->appends($request->all());

        // Normalize metrics for each individual transaction in the Detailed View
        $logs->getCollection()->transform(function($tx) {
            $employeeDiscount = (float)($tx->employee_discount ?? 0);
            if ($employeeDiscount === 0.0 && $tx->relationLoaded('adjustments')) {
                $employeeDiscount = (float) $tx->adjustments
                    ->whereIn('adjustment_type', ['employee_discount', 'EMPLOYEE'])
                    ->sum('amount');
            }

            $vipDiscount = (float)($tx->vip_card_discount ?? 0);
            if ($vipDiscount === 0.0 && $tx->relationLoaded('adjustments')) {
                $vipDiscount = (float) $tx->adjustments
                    ->whereIn('adjustment_type', ['vip_card_discount', 'VIP'])
                    ->sum('amount');
            }

            $components = [
                'vatable_sales' => (float)($tx->vatable_sales ?? 0),
                'sc_vat_exempt_sales' => (float)($tx->sc_vat_exempt_sales ?? 0),
                'vat_amount' => (float)($tx->vat_amount ?? 0),
                'promo_with_approval' => $tx->promo_status === 'WITH_APPROVAL' ? (float)($tx->promo_discount ?? 0) : 0,
                'promo_without_approval' => $tx->promo_status !== 'WITH_APPROVAL' ? (float)($tx->promo_discount ?? 0) : 0,
                'employee_discount' => $employeeDiscount,
                'senior_discount' => (float)($tx->senior_discount ?? 0),
                'pwd_discount' => (float)($tx->pwd_discount ?? 0),
                'vip_discount' => $vipDiscount,
                'other_tax' => (float)($tx->tax_exempt ?? 0),
                'service_charge_distributed' => (float)($tx->service_charge ?? 0),
                'service_charge_retained' => (float)($tx->management_service_charge ?? 0),
                'regular_discount' => (float)($tx->discount_total ?? 0),
                'gross_sales' => (float)($tx->gross_sales ?? 0),
                'net_sales' => (float)($tx->net_sales ?? 0),
            ];

            $derived = $this->financeService->deriveMetrics($components);

            // Override display values with normalized logic
            // We map back to 'amount' and 'vat' to maintain UI/test compatibility
            $tx->amount = $derived['gross_sales'];
            // For Detailed View "Net Sales" column, we match the Summary View "Net Total"
            // specifically INCLUDING Exempt sales for user-facing parity.
            $tx->net_sales = $derived['net_total'];
            $tx->vat = $derived['vat_amount'];
            $tx->vat_amount = $derived['vat_amount'];
            $tx->vatable_sales = $derived['vatable_sales'];
            $tx->sc_vat_exempt_sales = $derived['sc_vat_exempt_sales'];
            $tx->refund = (float)($tx->refund_amount ?? 0);
            $tx->employee_discount = $employeeDiscount;
            $tx->vip_discount = $vipDiscount;

            return $tx;
        });

        if ($request->wantsJson()) {
            return response()->json($logs);
        }

        // $providers = PosProvider::all();
        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id', 'serial_number', 'tenant_id', 'machine_number']);

        $tenants = Tenant::orderBy('trade_name')->get(['id', 'trade_name']);

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
                    'amount' => (float) $transaction->gross_sales,
                    'net_sales' => (float) $transaction->net_sales,
                    'validation_status' => $transaction->validation_status,
                    'is_voided' => $transaction->isVoided(),
                    'voided_at' => $transaction->voided_at,
                    'void_reason' => $transaction->void_reason,
                    'job_attempts' => (int) $transaction->job_attempts,
                    'created_at' => $transaction->created_at,
                    'completed_at' => $transaction->completed_at,
                    'terminal' => [
                        'serial_number' => $transaction->terminal->serial_number ?? 'N/A',
                        'machine_number' => $transaction->terminal->machine_number ?? null,
                        'tenant' => [
                            'trade_name' => $transaction->terminal->tenant->trade_name ?? 'N/A'
                        ],
                        'provider' => [
                            'name' => $transaction->terminal->provider->name ?? 'N/A'
                        ]
                    ],
                    'payload' => $transaction->original_payload ? json_decode($transaction->original_payload) : null,
                    'retry_history' => $transaction->jobs->map(function ($job) {
                        return [
                            'attempt' => $job->attempts ?? 1,
                            'status' => $job->job_status,
                            'attempted_at' => $job->created_at,
                            'error' => $job->last_error
                        ];
                    }),
                    'submission_events' => $transaction->validations->map(function ($v) {
                        return [
                            'submission_uuid' => $v->id, // or actual UUID if available
                            'status' => $v->status_code ?? 'VALIDATED',
                            'created_at' => $v->validated_at ?? $v->created_at
                        ];
                    }),
                    'horizon_job_tags' => ['transaction:' . $transaction->transaction_id, 'terminal:' . ($transaction->terminal->serial_number ?? 'unknown')]
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
        try {
            Gate::authorize('export-transaction-logs');

            $filters = $request->all();

            // Log export request for diagnostics (including filters and user context)
            Log::info('Transaction logs export requested', [
                'filters' => $filters,
                'user_id' => optional($request->user())->id,
                'guard' => optional($request->user())->getAuthIdentifierName() ?? null,
                'expects_json' => $request->expectsJson(),
                'path' => $request->path(),
            ]);

            $basis = in_array($filters['date_basis'] ?? null, ['created', 'completed', 'transaction'])
                ? $filters['date_basis']
                : 'transaction';
            $filename = 'transaction-logs-' . $basis . '-date-' . now()->format('Y-m-d') . '.xlsx';

            return Excel::download(new TransactionLogsExport($filters), $filename);
        } catch (\Throwable $e) {
            // Capture full error details so we can see the real 500 cause on staging
            Log::error('Transaction logs export failed', [
                'message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'filters' => $request->all(),
                'user_id' => optional($request->user())->id ?? null,
                'path' => $request->path(),
            ]);

            throw $e;
        }
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
     * Return a server-side count of transactions with validation_status = 'WITH_ISSUES'
     * Accepts the same filters as index() so callers can request counts that match
     * the current filter set. Returns JSON: { count: n }
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

        // Allow 'transaction' as a date basis which uses the canonical transaction_timestamp
        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction']) ? $request->input('date_basis') : 'transaction';
        $dateColumn = $basis === 'completed' ? 'completed_at' : ($basis === 'transaction' ? 'transaction_timestamp' : 'created_at');

        $query = Transaction::query();

        if ($request->filled('transaction_id')) {
            $search = str_replace('TX-', '', trim($request->transaction_id));
            $query->where('transaction_id', 'like', "%{$search}%");
        }

        // Apply status filter only if explicitly requested.
        if (isset($filters['status'])) {
            if ($filters['status'] === 'VOIDED') {
                $query->whereNotNull('voided_at');
            } elseif ($filters['status'] === 'REFUNDED') {
                $query->where('is_refunded', true);
            } else {
                $query->where('validation_status', $filters['status']);
            }
        }

        // Date filters - mirror logic used in index()/summary()
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

        // Only count rows that are WITH_ISSUES
        $count = $query->where('validation_status', 'WITH_ISSUES')->count();

        return response()->json(['count' => (int) $count]);
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

        // Allow 'transaction' as a date basis for summaries as well. For daily
        // roll-ups, prefer the generated/indexed business-date column so MySQL
        // can use idx_tx_tenant_date instead of scanning timestamp expressions.
        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction']) ? $request->input('date_basis') : 'transaction';
        $hasTransactionDate = Schema::hasColumn('transactions', 'transaction_date');
        // Allow client to control summary date ordering via sort_direction
        $sortDirection = strtolower($request->input('sort_direction')) === 'asc' ? 'asc' : 'desc';
        if ($basis === 'completed') {
            $dateColumn = 't.completed_at';
            $dateExpr = 't.completed_at';
        } elseif ($basis === 'transaction') {
            if ($hasTransactionDate) {
                $dateColumn = 't.transaction_date';
                $dateExpr = 't.transaction_date';
            } else {
                $dateColumn = 't.transaction_timestamp';
                // Older schemas may not have the generated date column yet.
                // Keep the previous fallback expression for those environments.
                $dateExpr = 'COALESCE(t.transaction_timestamp, t.created_at)';
            }
        } else {
            $dateColumn = 't.created_at';
            $dateExpr = 't.created_at';
        }

        // Determine pagination size like index(): if date filter provided and no per_page set, use 1000
        $perPage = (int) $request->input('per_page', 15);
        if (($request->filled('date_from') || $request->filled('date_to')) && !$request->has('per_page')) {
            $perPage = 1000;
        }

        $hasReceiptNo = Schema::hasColumn('transactions', 'receipt_no');
        $hasTaxExempt = Schema::hasColumn('transactions', 'tax_exempt');
        $hasEmployeeDiscount = Schema::hasColumn('transactions', 'employee_discount');
        $hasVipCardDiscount = Schema::hasColumn('transactions', 'vip_card_discount');
        $hasAdjustmentAggregates = Schema::hasTable('transaction_adjustments')
            && Schema::hasColumn('transaction_adjustments', 'transaction_pk');

        $employeeDiscountExpression = $hasAdjustmentAggregates
            ? 'COALESCE(SUM(COALESCE(adj_totals.employee_discount, 0)),0)'
            : '0';
        $vipDiscountExpression = $hasAdjustmentAggregates
            ? 'COALESCE(SUM(COALESCE(adj_totals.vip_discount, 0)),0)'
            : '0';

        if ($hasEmployeeDiscount) {
            $employeeDiscountExpression = $hasAdjustmentAggregates
                ? 'COALESCE(SUM(CASE WHEN COALESCE(t.employee_discount,0) <> 0 THEN t.employee_discount ELSE COALESCE(adj_totals.employee_discount,0) END),0)'
                : 'COALESCE(SUM(t.employee_discount),0)';
        }
        if ($hasVipCardDiscount) {
            $vipDiscountExpression = $hasAdjustmentAggregates
                ? 'COALESCE(SUM(CASE WHEN COALESCE(t.vip_card_discount,0) <> 0 THEN t.vip_card_discount ELSE COALESCE(adj_totals.vip_discount,0) END),0)'
                : 'COALESCE(SUM(t.vip_card_discount),0)';
        }

        // Build the filtered transaction set once, then clone it for grouped
        // pagination and ungrouped grand totals. Cloning after GROUP BY causes
        // the grand total query to scan grouped rows and can return the first
        // group instead of the true filtered total.
        $baseQuery = \DB::table('transactions as t')
            ->leftJoin('tenants as tn', 'tn.id', '=', 't.tenant_id')
            ->leftJoin('pos_terminals as term', 'term.id', '=', 't.terminal_id')
            ->when(isset($filters['status']), function ($q) use ($filters) {
                if ($filters['status'] === 'VOIDED') {
                    $q->whereNotNull('t.voided_at');
                } elseif ($filters['status'] === 'REFUNDED') {
                    $q->where('t.is_refunded', true);
                } else {
                    $q->where('t.validation_status', $filters['status']);
                }
            })
            ->when(isset($filters['date_from']), function ($q) use ($filters, $dateColumn) {
                // Apply date filtering based on selected date basis.
                // transaction_date is already DATE(COALESCE(transaction_timestamp, completed_at, created_at)).
                if ($dateColumn === 't.transaction_date') {
                    $q->where($dateColumn, '>=', $filters['date_from']);
                } elseif ($dateColumn === 't.transaction_timestamp') {
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
                if ($dateColumn === 't.transaction_date') {
                    $q->where($dateColumn, '<=', $filters['date_to']);
                } elseif ($dateColumn === 't.transaction_timestamp') {
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
                // [FIX-FINANCE-RECON] Use joined 'term' table for efficient resilient filtering in summary
                $q->where(function ($sub) use ($filters) {
                    $sub->where('t.tenant_id', $filters['tenant_id'])
                        ->orWhere('term.tenant_id', $filters['tenant_id']);
                });
            })
            ->when(isset($filters['terminal_id']), function ($q) use ($filters) {
                $q->where('t.terminal_id', $filters['terminal_id']);
            });

        if ($hasAdjustmentAggregates) {
            $adjustmentTotals = \DB::table('transaction_adjustments')
                ->selectRaw('transaction_pk')
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('employee_discount', 'EMPLOYEE') THEN amount ELSE 0 END) as employee_discount")
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('vip_card_discount', 'VIP') THEN amount ELSE 0 END) as vip_discount")
                ->groupBy('transaction_pk');

            $baseQuery->leftJoinSub($adjustmentTotals, 'adj_totals', function ($join) {
                $join->on('adj_totals.transaction_pk', '=', 't.id');
            });
        }

        // [FIX-FINANCE-RECON] When the schema supports receipt_no, default summary roll-ups to VALID
        // transactions so aggregates align with POS-style unique receipt counts.
        if ($hasReceiptNo && !isset($filters['status'])) {
            // Exclude DUPLICATE rows and VOIDED rows from financial summaries by default
            // to ensure Z-reading reconciliation matches (which typically subtracts voids).
            $baseQuery->where('t.validation_status', '!=', 'DUPLICATE')
                ->whereNull('t.voided_at');
        }

        $dateBasisDiscrepancy = null;
        if ($basis === 'transaction' && (isset($filters['date_from']) || isset($filters['date_to']))) {
            $discrepancyBaseQuery = \DB::table('transactions as t')
                ->leftJoin('pos_terminals as term', 'term.id', '=', 't.terminal_id')
                ->when(isset($filters['status']), function ($q) use ($filters) {
                    if ($filters['status'] === 'VOIDED') {
                        $q->whereNotNull('t.voided_at');
                    } elseif ($filters['status'] === 'REFUNDED') {
                        $q->where('t.is_refunded', true);
                    } else {
                        $q->where('t.validation_status', $filters['status']);
                    }
                })
                ->when(isset($filters['tenant_id']), function ($q) use ($filters) {
                    $q->where(function ($sub) use ($filters) {
                        $sub->where('t.tenant_id', $filters['tenant_id'])
                            ->orWhere('term.tenant_id', $filters['tenant_id']);
                    });
                })
                ->when(isset($filters['terminal_id']), function ($q) use ($filters) {
                    $q->where('t.terminal_id', $filters['terminal_id']);
                });

            if ($hasReceiptNo && !isset($filters['status'])) {
                $discrepancyBaseQuery->where('t.validation_status', '!=', 'DUPLICATE')
                    ->whereNull('t.voided_at');
            }

            $completedDateQuery = clone $discrepancyBaseQuery;
            if (isset($filters['date_from'])) {
                $completedDateQuery->where('t.completed_at', '>=', $filters['date_from'] . ' 00:00:00');
            }
            if (isset($filters['date_to'])) {
                $completedDateQuery->where('t.completed_at', '<=', $filters['date_to'] . ' 23:59:59');
            }

            $completedOutsideRangeQuery = clone $baseQuery;
            $completedOutsideRangeQuery->where(function ($q) use ($filters) {
                $q->whereNull('t.completed_at');

                if (isset($filters['date_from'])) {
                    $q->orWhere('t.completed_at', '<', $filters['date_from'] . ' 00:00:00');
                }

                if (isset($filters['date_to'])) {
                    $q->orWhere('t.completed_at', '>', $filters['date_to'] . ' 23:59:59');
                }
            });

            $eventOutsideRangeQuery = clone $completedDateQuery;
            if ($hasTransactionDate) {
                $eventOutsideRangeQuery->where(function ($q) use ($filters) {
                    if (isset($filters['date_from'])) {
                        $q->orWhere('t.transaction_date', '<', $filters['date_from']);
                    }

                    if (isset($filters['date_to'])) {
                        $q->orWhere('t.transaction_date', '>', $filters['date_to']);
                    }
                });
            } else {
                $eventOutsideRangeQuery->where(function ($q) use ($filters) {
                    if (isset($filters['date_from'])) {
                        $q->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('t.transaction_timestamp')
                                ->where('t.transaction_timestamp', '<', $filters['date_from'] . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('t.transaction_timestamp')
                                ->where('t.created_at', '<', $filters['date_from'] . ' 00:00:00');
                        });
                    }

                    if (isset($filters['date_to'])) {
                        $q->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('t.transaction_timestamp')
                                ->where('t.transaction_timestamp', '>', $filters['date_to'] . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('t.transaction_timestamp')
                                ->where('t.created_at', '>', $filters['date_to'] . ' 23:59:59');
                        });
                    }
                });
            }

            $transactionDateCount = (int) (clone $baseQuery)->count();
            $completedDateCount = (int) $completedDateQuery->count();

            $dateBasisDiscrepancy = [
                'basis' => 'transaction',
                'transaction_date_count' => $transactionDateCount,
                'completed_date_count' => $completedDateCount,
                'net_difference' => $completedDateCount - $transactionDateCount,
                'event_date_rows_completed_outside_range' => (int) $completedOutsideRangeQuery->count(),
                'completed_date_rows_with_event_outside_range' => (int) $eventOutsideRangeQuery->count(),
            ];
        }

        // For transaction-basis summary roll-ups, transaction_date is already a
        // daily value; timestamp bases still need DATE(...) for grouping.
        $summaryDateSelect = $dateColumn === 't.transaction_date'
            ? $dateExpr . ' as date'
            : 'DATE(' . $dateExpr . ') as date';

        $query = (clone $baseQuery)
            ->selectRaw($summaryDateSelect)
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw('COALESCE(tn.trade_name, "Unknown") as trade_name')
            ->selectRaw('term.serial_number, term.machine_number')
            ->selectRaw('COUNT(*) as tx_count')
            // If receipt_no exists, also surface unique receipt counts so the
            // UI can present provider-style counts (COUNT DISTINCT receipt_no).
            ->when($hasReceiptNo, function ($q) {
                // NULLIF guards against empty-string receipt_no values being counted
                // as distinct; treat empty strings as NULL so they are excluded.
                $q->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts");
            })
        // Use stored gross_sales as the canonical gross for summary so it matches
        // the Detailed view and POS Z-reading totals.
        ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross_sales')
        ->selectRaw('COALESCE(SUM(t.net_sales),0) as raw_net_sales')
        ->selectRaw('COALESCE(SUM(t.vat_amount),0) as raw_vat_amount')
        ->selectRaw('COALESCE(SUM(t.vatable_sales),0) as raw_vatable_sales')
        ->selectRaw('COALESCE(SUM(t.sc_vat_exempt_sales),0) as raw_sc_vat_exempt_sales')
        ->selectRaw('COALESCE(SUM(t.refund_amount),0) as refund')

        // Add granular components for FinanceCalculationService normalization
        ->selectRaw("COALESCE(SUM(CASE WHEN t.promo_status = 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0) as promo_with_approval")
        ->selectRaw("COALESCE(SUM(CASE WHEN t.promo_status != 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0) as promo_without_approval")
        ->selectRaw('COALESCE(SUM(t.senior_discount),0) as senior_discount')
        ->selectRaw('COALESCE(SUM(t.pwd_discount),0) as pwd_discount')
        ->selectRaw('COALESCE(SUM(t.discount_total),0) as regular_discount')
        ->selectRaw('COALESCE(SUM(t.service_charge),0) as service_charge_distributed')
        ->selectRaw('COALESCE(SUM(t.management_service_charge),0) as service_charge_retained')
        // other_tax: derived from transactions_taxes relation is complex in a GROUP BY.
        // We will sum the transaction-level tax_exempt column as a proxy if it exists.
        ->when($hasTaxExempt, function ($q) {
            $q->selectRaw('COALESCE(SUM(t.tax_exempt),0) as other_tax');
        })
        ->selectRaw($employeeDiscountExpression . ' as employee_discount')
        ->selectRaw($vipDiscountExpression . ' as vip_discount')
        ->selectRaw('MIN(t.id) as sample_tx_id')
        ->groupBy('date', 't.tenant_id', 't.terminal_id', 'trade_name', 'term.serial_number', 'term.machine_number')
        ->orderBy('date', $sortDirection);

        // Clone the query for global grand totals before grouping and pagination.
        // This provides an overall total for the entire filtered set across all pages.
        $grandTotalQuery = clone $baseQuery;
        $grandTotalRaw = $grandTotalQuery
            ->selectRaw('COUNT(*) as tx_count')
            ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(t.net_sales),0) as raw_net_sales')
            ->selectRaw('COALESCE(SUM(t.vat_amount),0) as raw_vat_amount')
            ->selectRaw('COALESCE(SUM(t.vatable_sales),0) as raw_vatable_sales')
            ->selectRaw('COALESCE(SUM(t.sc_vat_exempt_sales),0) as raw_sc_vat_exempt_sales')
            ->selectRaw('COALESCE(SUM(t.refund_amount),0) as refund')
            ->selectRaw("COALESCE(SUM(CASE WHEN t.promo_status = 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0) as promo_with_approval")
            ->selectRaw("COALESCE(SUM(CASE WHEN t.promo_status != 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0) as promo_without_approval")
            ->selectRaw('COALESCE(SUM(t.senior_discount),0) as senior_discount')
            ->selectRaw('COALESCE(SUM(t.pwd_discount),0) as pwd_discount')
            ->selectRaw('COALESCE(SUM(t.discount_total),0) as regular_discount')
            ->selectRaw('COALESCE(SUM(t.service_charge),0) as service_charge_distributed')
            ->selectRaw('COALESCE(SUM(t.management_service_charge),0) as service_charge_retained')
            ->when($hasTaxExempt, function ($q) {
                $q->selectRaw('COALESCE(SUM(t.tax_exempt),0) as other_tax');
            })
            ->selectRaw($employeeDiscountExpression . ' as employee_discount')
            ->selectRaw($vipDiscountExpression . ' as vip_discount')
            ->when($hasReceiptNo, function ($q) {
                $q->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts");
            })
            ->first();

        // Standardize the grand total using FinanceCalculationService logic
        $gtComponents = [
            'vatable_sales' => (float)$grandTotalRaw->raw_vatable_sales,
            'sc_vat_exempt_sales' => (float)$grandTotalRaw->raw_sc_vat_exempt_sales,
            'vat_amount' => (float)$grandTotalRaw->raw_vat_amount,
            'promo_with_approval' => (float)$grandTotalRaw->promo_with_approval,
            'promo_without_approval' => (float)$grandTotalRaw->promo_without_approval,
            'employee_discount' => (float)($grandTotalRaw->employee_discount ?? 0),
            'senior_discount' => (float)$grandTotalRaw->senior_discount,
            'pwd_discount' => (float)$grandTotalRaw->pwd_discount,
            'vip_discount' => (float)($grandTotalRaw->vip_discount ?? 0),
            'other_tax' => (float)($grandTotalRaw->other_tax ?? 0),
            'service_charge_distributed' => (float)$grandTotalRaw->service_charge_distributed,
            'service_charge_retained' => (float)$grandTotalRaw->service_charge_retained,
            'regular_discount' => (float)$grandTotalRaw->regular_discount,
            'gross_sales' => (float)$grandTotalRaw->gross_sales,
            'net_sales' => (float)$grandTotalRaw->raw_net_sales,
        ];

        $gtDerived = $this->financeService->deriveMetrics($gtComponents);
        $grandTotal = (object)[
            'tx_count' => $grandTotalRaw->tx_count,
            'unique_receipts' => $grandTotalRaw->unique_receipts ?? 0,
            'gross' => $gtDerived['gross_sales'],
            'net' => $gtDerived['net_total'],
            'refund' => (float)$grandTotalRaw->refund,
            'promo_discount' => $gtDerived['total_promotions'],
            'senior_discount' => (float)$grandTotalRaw->senior_discount,
            'pwd_discount' => (float)$grandTotalRaw->pwd_discount,
            'vip_discount' => (float)($grandTotalRaw->vip_discount ?? 0),
            'employee_discount' => (float)($grandTotalRaw->employee_discount ?? 0),
            'service_charge' => $gtDerived['service_charge_distributed'],
            'management_service_charge' => $gtDerived['service_charge_retained'],
            'vat' => $gtDerived['vat_amount'],
            'vatable_sales' => $gtDerived['vatable_sales'],
            'sc_vat_exempt_sales' => $gtDerived['sc_vat_exempt_sales'],
            'tax_exempt' => $gtDerived['other_tax'],
            'other_tax' => 0, // Placeholder as in row logic
        ];

        $summary = $query->paginate($perPage)->appends($request->all());

        // Standardize the numeric roll-ups using FinanceCalculationService logic.
        // This ensures the Dashboard Summary matches the Certified PDF reports.
        $summary->getCollection()->transform(function($row) {
            $components = [
                'vatable_sales' => (float)$row->raw_vatable_sales,
                'sc_vat_exempt_sales' => (float)$row->raw_sc_vat_exempt_sales,
                'vat_amount' => (float)$row->raw_vat_amount,
                'promo_with_approval' => (float)$row->promo_with_approval,
                'promo_without_approval' => (float)$row->promo_without_approval,
                'employee_discount' => (float)($row->employee_discount ?? 0),
                'senior_discount' => (float)$row->senior_discount,
                'pwd_discount' => (float)$row->pwd_discount,
                'vip_discount' => (float)($row->vip_discount ?? 0),
                'other_tax' => (float)($row->other_tax ?? 0),
                'service_charge_distributed' => (float)$row->service_charge_distributed,
                'service_charge_retained' => (float)$row->service_charge_retained,
                'regular_discount' => (float)$row->regular_discount,
                'gross_sales' => (float)$row->gross_sales,
                'net_sales' => (float)$row->raw_net_sales,
            ];

            $derived = $this->financeService->deriveMetrics($components);

            // Override specific display columns with normalized values
            $row->gross = $derived['gross_sales'];
            // The dashboard Net Total matches the CMSR bottom line ($Vatable + VAT + Exempt)
            // We use the unified net_total from the service to avoid double-counting.
            $row->net = $derived['net_total'];
            $row->vat = $derived['vat_amount'];
            $row->vatable_sales = $derived['vatable_sales'];
            $row->sc_vat_exempt_sales = $derived['sc_vat_exempt_sales'];
            $row->tax_exempt = $derived['other_tax'];

            // Map computed aggregate groups for UI display (Discounts column)
            $row->senior_pwd = $derived['senior_pwd'];
            $row->promo_discount = $derived['total_promotions'];

            return $row;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'summary' => $summary,
                'grandTotal' => $grandTotal,
                'dateBasisDiscrepancy' => $dateBasisDiscrepancy,
            ]);
        }

        // Fetch Blade-only data after the JSON response path so the React summary
        // view does not pay for representative transactions or filter lists.
        $sampleIds = collect($summary->items())->pluck('sample_tx_id')->filter()->unique()->values()->all();
        $sampleTransactions = [];
        if (!empty($sampleIds)) {
            $sampleTransactions = Transaction::with(['adjustments', 'taxes', 'terminal', 'tenant'])
                ->whereIn('id', $sampleIds)
                ->get()
                ->keyBy('id');
        }

        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id', 'serial_number', 'tenant_id', 'machine_number']);
        $tenants = Tenant::orderBy('trade_name')->get(['id', 'trade_name']);

        $activeTab = 'summary';
        $logs = collect(); // not needed on summary route

        return view('transactions.logs.index', compact('logs', 'terminals', 'tenants', 'filters', 'activeTab', 'summary', 'sampleTransactions', 'grandTotal'));
    }
}
