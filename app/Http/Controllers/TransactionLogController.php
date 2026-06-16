<?php

namespace App\Http\Controllers;

use App\Events\TransactionLogUpdated;
use App\Exports\TransactionLogsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\TransactionLogService;
use App\Services\TransactionDetailService;
use Illuminate\Support\Facades\Gate;
use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TransactionLogController extends Controller
{
    protected $logService;
    protected $detailService;
    protected $financeService;

    public function __construct(
        TransactionLogService $logService,
        TransactionDetailService $detailService,
        FinanceCalculationService $financeService
    )
    {
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

        $hasBoundedFilters = collect($filters)->contains(function ($value) {
            return $value !== null && $value !== '';
        });

        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction'], true)
            ? $request->input('date_basis')
            : 'transaction';

        $hasTransactionDate = Schema::hasColumn('transactions', 'transaction_date');
        $dateColumn = match ($basis) {
            'created' => 'created_at',
            'completed' => 'completed_at',
            default => $hasTransactionDate ? 'transaction_date' : 'transaction_timestamp',
        };

        $logs = Transaction::select([
            'id',
            'transaction_id',
            'terminal_id',
            'gross_sales as amount',
            'net_sales',
            'receipt_no',
            'transaction_timestamp',
            'validation_status',
            'vat_amount as vat',
            'vatable_sales',
            'sc_vat_exempt_sales',
            'tax_exempt',
            'management_service_charge',
            'refund_amount as refund',
            'is_refunded',
            'voided_at',
            'void_reason',
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
                if ($dateColumn === 'transaction_date') {
                    return $query->where($dateColumn, '>=', $filters['date_from']);
                }

                if ($dateColumn === 'transaction_timestamp') {
                    return $query->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('transaction_timestamp')
                                ->where('transaction_timestamp', '>=', $filters['date_from'] . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('transaction_timestamp')
                                ->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
                        });
                    });
                }

                return $query->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($query) use ($filters, $dateColumn) {
                if ($dateColumn === 'transaction_date') {
                    return $query->where($dateColumn, '<=', $filters['date_to']);
                }

                if ($dateColumn === 'transaction_timestamp') {
                    return $query->where(function ($q) use ($filters) {
                        $q->where(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('transaction_timestamp')
                                ->where('transaction_timestamp', '<=', $filters['date_to'] . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('transaction_timestamp')
                                ->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
                        });
                    });
                }

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
            ->when(! $hasBoundedFilters, function ($query) {
                return $query->orderByDesc('id');
            })
            ->when($hasBoundedFilters && $dateColumn === 'transaction_date', function ($query) {
                return $query->orderByDesc('transaction_date')->orderByDesc('id');
            })
            ->when($hasBoundedFilters && $dateColumn === 'transaction_timestamp', function ($query) {
                return $query->orderByRaw('COALESCE(transaction_timestamp, created_at) desc');
            })
            ->when($hasBoundedFilters && ! in_array($dateColumn, ['transaction_date', 'transaction_timestamp'], true), function ($query) use ($dateColumn) {
                return $query->orderBy($dateColumn, 'desc');
            });

        $logs = $hasBoundedFilters
            ? $logs->paginate($perPage)->appends($request->all())
            : $logs->simplePaginate($perPage)->appends($request->all());

        if ($request->wantsJson()) {
            $payload = $logs->toArray();
            if (! $hasBoundedFilters) {
                $payload['total'] = -1;
            }

            return response()->json($payload);
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
                    'transaction_timestamp' => $transaction->transaction_timestamp,
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
                    'payload' => $this->resolveTransactionPayload($transaction),
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
        return (new TransactionLogsExport($request->all()))->download($filename);
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
     * Resolve the best-available full payload for a transaction.
     *
     * Priority:
     * 1) Stored original_payload on transactions table
     * 2) Matching payload from transaction_intake (by submission_uuid + transaction_id)
     * 3) Reconstructed payload from normalized transaction + adjustments + taxes
     */
    private function resolveTransactionPayload(Transaction $transaction)
    {
        if (!empty($transaction->original_payload)) {
            $decoded = json_decode($transaction->original_payload, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
                return $decoded;
            }
        }

        $submissionUuid = $transaction->submission_uuid;
        if (!empty($submissionUuid)) {
            $intake = TransactionIntake::query()
                ->where('submission_uuid', $submissionUuid)
                ->when($transaction->terminal_id, function ($q) use ($transaction) {
                    $q->where('terminal_id', $transaction->terminal_id);
                })
                ->orderByDesc('received_at')
                ->first();

            if ($intake && is_array($intake->payload) && !empty($intake->payload)) {
                $payload = $intake->payload;

                // If a batch payload exists, keep only the matching transaction
                // so the details drawer remains specific to the selected row.
                if (isset($payload['transactions']) && is_array($payload['transactions'])) {
                    $matched = collect($payload['transactions'])
                        ->firstWhere('transaction_id', $transaction->transaction_id);

                    if (!empty($matched)) {
                        $payload['transactions'] = [$matched];
                        $payload['transaction_count'] = 1;
                    }
                } elseif (isset($payload['transaction']) && is_array($payload['transaction'])) {
                    $payloadTxId = $payload['transaction']['transaction_id'] ?? null;
                    if ($payloadTxId && $payloadTxId !== $transaction->transaction_id) {
                        // Submission payload exists but points to a different transaction;
                        // prefer deterministic reconstruction for this row.
                        return $this->buildPayloadFromTransaction($transaction);
                    }
                }

                return $payload;
            }
        }

        return $this->buildPayloadFromTransaction($transaction);
    }

    /**
     * Deterministic reconstruction of payload when original envelope is unavailable.
     */
    private function buildPayloadFromTransaction(Transaction $transaction): array
    {
        return [
            'submission_uuid' => $transaction->submission_uuid,
            'submission_timestamp' => optional($transaction->submission_timestamp)->toIso8601String(),
            'tenant_id' => $transaction->tenant_id,
            'terminal_id' => $transaction->terminal_id,
            'transaction_count' => 1,
            'payload_checksum' => $transaction->payload_checksum,
            'transaction' => [
                'transaction_id' => $transaction->transaction_id,
                'transaction_timestamp' => optional($transaction->transaction_timestamp)->toIso8601String(),
                'receipt_no' => $transaction->receipt_no,
                'gross_sales' => (float) ($transaction->gross_sales ?? 0),
                'net_sales' => (float) ($transaction->net_sales ?? 0),
                'vatable_sales' => (float) ($transaction->vatable_sales ?? 0),
                'vat_amount' => (float) ($transaction->vat_amount ?? 0),
                'sc_vat_exempt_sales' => (float) ($transaction->sc_vat_exempt_sales ?? 0),
                'customer_code' => $transaction->customer_code,
                'promo_status' => $transaction->promo_status,
                'payload_checksum' => $transaction->payload_checksum,
                'adjustments' => $transaction->adjustments->map(function ($adj) {
                    return [
                        'adjustment_type' => $adj->adjustment_type,
                        'amount' => (float) ($adj->amount ?? 0),
                    ];
                })->values()->all(),
                'taxes' => $transaction->taxes->map(function ($tax) {
                    return [
                        'tax_type' => $tax->tax_type,
                        'amount' => (float) ($tax->amount ?? 0),
                    ];
                })->values()->all(),
            ],
        ];
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
            : 'transaction';

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

        $basis = in_array($request->input('date_basis'), ['created', 'completed', 'transaction'], true)
            ? $request->input('date_basis')
            : 'transaction';

        $hasTransactionDate = Schema::hasColumn('transactions', 'transaction_date');
        if ($basis === 'completed') {
            $dateColumn = 't.completed_at';
            $dateExpr = 't.completed_at';
        } elseif ($basis === 'transaction' && $hasTransactionDate) {
            $dateColumn = 't.transaction_date';
            $dateExpr = 't.transaction_date';
        } elseif ($basis === 'transaction') {
            $dateColumn = 't.transaction_timestamp';
            $dateExpr = 'COALESCE(t.transaction_timestamp, t.created_at)';
        } else {
            $dateColumn = 't.created_at';
            $dateExpr = 't.created_at';
        }

        $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Determine pagination size like index(): if date filter provided and no per_page set, use 1000
        $perPage = (int) $request->input('per_page', 15);
        if (($request->filled('date_from') || $request->filled('date_to')) && !$request->has('per_page')) {
            $perPage = 1000;
        }

        $hasBoundedFilters = collect($filters)->contains(function ($value) {
            return $value !== null && $value !== '';
        });

        $hasReceiptNo = Schema::hasColumn('transactions', 'receipt_no');
        $hasTaxExempt = Schema::hasColumn('transactions', 'tax_exempt');
        $hasEmployeeDiscount = Schema::hasColumn('transactions', 'employee_discount');
        $hasVipCardDiscount = Schema::hasColumn('transactions', 'vip_card_discount');
        $hasPromoDiscount = Schema::hasColumn('transactions', 'promo_discount');
        $hasPromoStatus = Schema::hasColumn('transactions', 'promo_status');
        $hasSeniorDiscount = Schema::hasColumn('transactions', 'senior_discount');
        $hasPwdDiscount = Schema::hasColumn('transactions', 'pwd_discount');
        $hasDiscountTotal = Schema::hasColumn('transactions', 'discount_total');
        $hasRefundAmount = Schema::hasColumn('transactions', 'refund_amount');
        $hasServiceCharge = Schema::hasColumn('transactions', 'service_charge');
        $hasManagementServiceCharge = Schema::hasColumn('transactions', 'management_service_charge');
        $hasAdjustmentAggregates = Schema::hasTable('transaction_adjustments')
            && Schema::hasColumn('transaction_adjustments', 'transaction_pk');

        $promoWithApprovalExpression = ($hasPromoDiscount && $hasPromoStatus)
            ? "COALESCE(SUM(CASE WHEN t.promo_status = 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0)"
            : '0';
        $promoWithoutApprovalExpression = $hasPromoDiscount
            ? ($hasPromoStatus
                ? "COALESCE(SUM(CASE WHEN t.promo_status != 'WITH_APPROVAL' OR t.promo_status IS NULL THEN t.promo_discount ELSE 0 END),0)"
                : 'COALESCE(SUM(t.promo_discount),0)')
            : '0';
        $seniorDiscountExpression = $hasSeniorDiscount ? 'COALESCE(SUM(t.senior_discount),0)' : '0';
        $pwdDiscountExpression = $hasPwdDiscount ? 'COALESCE(SUM(t.pwd_discount),0)' : '0';
        $regularDiscountExpression = $hasDiscountTotal ? 'COALESCE(SUM(t.discount_total),0)' : '0';
        $refundExpression = $hasRefundAmount ? 'COALESCE(SUM(t.refund_amount),0)' : '0';
        $serviceChargeExpression = $hasServiceCharge ? 'COALESCE(SUM(t.service_charge),0)' : '0';
        $managementServiceChargeExpression = $hasManagementServiceCharge ? 'COALESCE(SUM(t.management_service_charge),0)' : '0';
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

        $baseQuery = DB::table('transactions as t')
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
                if ($dateColumn === 't.transaction_date') {
                    $q->where($dateColumn, '>=', $filters['date_from']);
                } elseif ($dateColumn === 't.transaction_timestamp') {
                    $q->where(function ($nested) use ($filters) {
                        $nested->where(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('t.transaction_timestamp')
                                ->where('t.transaction_timestamp', '>=', $filters['date_from'] . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('t.transaction_timestamp')
                                ->where('t.created_at', '>=', $filters['date_from'] . ' 00:00:00');
                        });
                    });

                    return;
                }

                $q->where($dateColumn, '>=', $filters['date_from'] . ' 00:00:00');
            })
            ->when(isset($filters['date_to']), function ($q) use ($filters, $dateColumn) {
                if ($dateColumn === 't.transaction_date') {
                    $q->where($dateColumn, '<=', $filters['date_to']);
                } elseif ($dateColumn === 't.transaction_timestamp') {
                    $q->where(function ($nested) use ($filters) {
                        $nested->where(function ($subQ) use ($filters) {
                            $subQ->whereNotNull('t.transaction_timestamp')
                                ->where('t.transaction_timestamp', '<=', $filters['date_to'] . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($filters) {
                            $subQ->whereNull('t.transaction_timestamp')
                                ->where('t.created_at', '<=', $filters['date_to'] . ' 23:59:59');
                        });
                    });

                    return;
                }

                $q->where($dateColumn, '<=', $filters['date_to'] . ' 23:59:59');
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

        if ($hasAdjustmentAggregates) {
            $adjustmentTotals = DB::table('transaction_adjustments')
                ->selectRaw('transaction_pk')
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('employee_discount', 'EMPLOYEE') THEN amount ELSE 0 END) as employee_discount")
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('vip_card_discount', 'VIP') THEN amount ELSE 0 END) as vip_discount")
                ->groupBy('transaction_pk');

            $baseQuery->leftJoinSub($adjustmentTotals, 'adj_totals', function ($join) {
                $join->on('adj_totals.transaction_pk', '=', 't.id');
            });
        }

        if ($hasReceiptNo && !isset($filters['status'])) {
            $baseQuery->where('t.validation_status', '!=', 'DUPLICATE')
                ->whereNull('t.voided_at');
        }

        $dateBasisDiscrepancy = null;
        $includeDateBasisDiscrepancy = $request->boolean('include_date_basis_discrepancy', false);
        if ($includeDateBasisDiscrepancy && $basis === 'transaction' && (isset($filters['date_from']) || isset($filters['date_to']))) {
            $discrepancyBaseQuery = DB::table('transactions as t')
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

        $grandTotal = null;
        if ($hasBoundedFilters) {
            $grandTotalRaw = (clone $baseQuery)
                ->selectRaw('COUNT(*) as tx_count')
                ->when($hasReceiptNo, function ($q) {
                    $q->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts");
                }, function ($q) {
                    $q->selectRaw('COUNT(*) as unique_receipts');
                })
                ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross_sales')
                ->selectRaw('COALESCE(SUM(t.net_sales),0) as raw_net_sales')
                ->selectRaw('COALESCE(SUM(t.vat_amount),0) as raw_vat_amount')
                ->selectRaw('COALESCE(SUM(t.vatable_sales),0) as raw_vatable_sales')
                ->selectRaw('COALESCE(SUM(t.sc_vat_exempt_sales),0) as raw_sc_vat_exempt_sales')
                ->selectRaw($refundExpression . ' as refund')
                ->selectRaw($promoWithApprovalExpression . ' as promo_with_approval')
                ->selectRaw($promoWithoutApprovalExpression . ' as promo_without_approval')
                ->selectRaw($seniorDiscountExpression . ' as senior_discount')
                ->selectRaw($pwdDiscountExpression . ' as pwd_discount')
                ->selectRaw($regularDiscountExpression . ' as regular_discount')
                ->selectRaw($serviceChargeExpression . ' as service_charge_distributed')
                ->selectRaw($managementServiceChargeExpression . ' as service_charge_retained')
                ->when($hasTaxExempt, function ($q) {
                    $q->selectRaw('COALESCE(SUM(t.tax_exempt),0) as other_tax');
                }, function ($q) {
                    $q->selectRaw('0 as other_tax');
                })
                ->selectRaw($employeeDiscountExpression . ' as employee_discount')
                ->selectRaw($vipDiscountExpression . ' as vip_discount')
                ->first();

            $grandTotalComponents = [
                'vatable_sales' => (float) $grandTotalRaw->raw_vatable_sales,
                'sc_vat_exempt_sales' => (float) $grandTotalRaw->raw_sc_vat_exempt_sales,
                'vat_amount' => (float) $grandTotalRaw->raw_vat_amount,
                'promo_with_approval' => (float) $grandTotalRaw->promo_with_approval,
                'promo_without_approval' => (float) $grandTotalRaw->promo_without_approval,
                'employee_discount' => (float) ($grandTotalRaw->employee_discount ?? 0),
                'senior_discount' => (float) $grandTotalRaw->senior_discount,
                'pwd_discount' => (float) $grandTotalRaw->pwd_discount,
                'vip_discount' => (float) ($grandTotalRaw->vip_discount ?? 0),
                'other_tax' => (float) ($grandTotalRaw->other_tax ?? 0),
                'service_charge_distributed' => (float) $grandTotalRaw->service_charge_distributed,
                'service_charge_retained' => (float) $grandTotalRaw->service_charge_retained,
                'regular_discount' => (float) $grandTotalRaw->regular_discount,
                'gross_sales' => (float) $grandTotalRaw->gross_sales,
                'net_sales' => (float) $grandTotalRaw->raw_net_sales,
            ];
            $grandTotalDerived = $this->financeService->deriveMetrics($grandTotalComponents);
            $grandTotal = (object) [
                'tx_count' => (int) $grandTotalRaw->tx_count,
                'unique_receipts' => (int) ($grandTotalRaw->unique_receipts ?? 0),
                'gross' => $grandTotalDerived['gross_sales'],
                'net' => $grandTotalDerived['net_total'],
                'refund' => (float) $grandTotalRaw->refund,
                'promo_discount' => $grandTotalDerived['total_promotions'],
                'senior_discount' => (float) $grandTotalRaw->senior_discount,
                'pwd_discount' => (float) $grandTotalRaw->pwd_discount,
                'vip_discount' => (float) ($grandTotalRaw->vip_discount ?? 0),
                'employee_discount' => (float) ($grandTotalRaw->employee_discount ?? 0),
                'service_charge' => $grandTotalDerived['service_charge_distributed'],
                'service_charge_distributed' => $grandTotalDerived['service_charge_distributed'],
                'management_service_charge' => $grandTotalDerived['service_charge_retained'],
                'service_charge_retained' => $grandTotalDerived['service_charge_retained'],
                'vat' => $grandTotalDerived['vat_amount'],
                'vatable_sales' => $grandTotalDerived['vatable_sales'],
                'sc_vat_exempt_sales' => $grandTotalDerived['sc_vat_exempt_sales'],
                'tax_exempt' => $grandTotalDerived['other_tax'],
                'other_tax' => $grandTotalDerived['other_tax'],
            ];
        }

        $summaryDateSelect = $dateColumn === 't.transaction_date'
            ? $dateExpr . ' as date'
            : 'DATE(' . $dateExpr . ') as date';

        $query = (clone $baseQuery)
            ->selectRaw($summaryDateSelect)
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw('COALESCE(tn.trade_name, "Unknown") as trade_name')
            ->selectRaw('term.serial_number, term.machine_number')
            ->selectRaw('COUNT(*) as tx_count')
            ->when($hasReceiptNo, function ($q) {
                $q->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts");
            }, function ($q) {
                $q->selectRaw('COUNT(*) as unique_receipts');
            })
            ->selectRaw('COALESCE(SUM(t.gross_sales),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(t.net_sales),0) as raw_net_sales')
            ->selectRaw('COALESCE(SUM(t.vat_amount),0) as raw_vat_amount')
            ->selectRaw('COALESCE(SUM(t.vatable_sales),0) as raw_vatable_sales')
            ->selectRaw('COALESCE(SUM(t.sc_vat_exempt_sales),0) as raw_sc_vat_exempt_sales')
            ->selectRaw($refundExpression . ' as refund')
            ->selectRaw($promoWithApprovalExpression . ' as promo_with_approval')
            ->selectRaw($promoWithoutApprovalExpression . ' as promo_without_approval')
            ->selectRaw($seniorDiscountExpression . ' as senior_discount')
            ->selectRaw($pwdDiscountExpression . ' as pwd_discount')
            ->selectRaw($regularDiscountExpression . ' as regular_discount')
            ->selectRaw($serviceChargeExpression . ' as service_charge_distributed')
            ->selectRaw($managementServiceChargeExpression . ' as service_charge_retained')
            ->when($hasTaxExempt, function ($q) {
                $q->selectRaw('COALESCE(SUM(t.tax_exempt),0) as other_tax');
            }, function ($q) {
                $q->selectRaw('0 as other_tax');
            })
            ->selectRaw($employeeDiscountExpression . ' as employee_discount')
            ->selectRaw($vipDiscountExpression . ' as vip_discount')
            ->selectRaw('MIN(t.id) as sample_tx_id')
            ->groupBy('date', 't.tenant_id', 't.terminal_id', 'trade_name', 'term.serial_number', 'term.machine_number')
            ->orderBy('date', $sortDirection);

        $summary = $hasBoundedFilters
            ? $query->paginate($perPage)->appends($request->all())
            : $query->simplePaginate($perPage)->appends($request->all());

        $summary->getCollection()->transform(function ($row) {
            $components = [
                'vatable_sales' => (float) $row->raw_vatable_sales,
                'sc_vat_exempt_sales' => (float) $row->raw_sc_vat_exempt_sales,
                'vat_amount' => (float) $row->raw_vat_amount,
                'promo_with_approval' => (float) $row->promo_with_approval,
                'promo_without_approval' => (float) $row->promo_without_approval,
                'employee_discount' => (float) ($row->employee_discount ?? 0),
                'senior_discount' => (float) $row->senior_discount,
                'pwd_discount' => (float) $row->pwd_discount,
                'vip_discount' => (float) ($row->vip_discount ?? 0),
                'other_tax' => (float) ($row->other_tax ?? 0),
                'service_charge_distributed' => (float) $row->service_charge_distributed,
                'service_charge_retained' => (float) $row->service_charge_retained,
                'regular_discount' => (float) $row->regular_discount,
                'gross_sales' => (float) $row->gross_sales,
                'net_sales' => (float) $row->raw_net_sales,
            ];

            $derived = $this->financeService->deriveMetrics($components);

            $row->gross = $derived['gross_sales'];
            $row->net = $derived['net_total'];
            $row->vat = $derived['vat_amount'];
            $row->vatable_sales = $derived['vatable_sales'];
            $row->sc_vat_exempt_sales = $derived['sc_vat_exempt_sales'];
            $row->tax_exempt = $derived['other_tax'];
            $row->other_tax = $derived['other_tax'];
            $row->senior_pwd = $derived['senior_pwd'];
            $row->promo_discount = $derived['total_promotions'];

            return $row;
        });

        if ($request->wantsJson()) {
            $summaryPayload = $summary->toArray();
            if (! $hasBoundedFilters) {
                $summaryPayload['total'] = -1;
            }

            return response()->json([
                'summary' => $summaryPayload,
                'grandTotal' => $grandTotal,
                'dateBasisDiscrepancy' => $dateBasisDiscrepancy,
            ]);
        }

        $terminals = PosTerminal::with('tenant:id,trade_name')
            ->get(['id','serial_number','tenant_id','machine_number']);
        $tenants = Tenant::orderBy('trade_name')->get(['id','trade_name']);

        $activeTab = 'summary';
        $logs = collect(); // not needed on summary route

        return view('transactions.logs.index', compact('logs', 'terminals', 'tenants', 'filters', 'activeTab', 'summary', 'grandTotal'));
    }
}
