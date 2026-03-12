<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use App\Jobs\ProcessTransactionJob;
use App\Jobs\CheckTransactionFailureThresholdsJob;
use App\Services\PayloadChecksumService;
use App\Services\NotificationService;
use App\Http\Requests\TSMSTransactionRequest;
use Laravel\Sanctum\PersonalAccessToken;
use Carbon\Carbon;
// Removed duplicate Cache import

class TransactionController extends Controller
{
    /**
     * Emit a submission-level event safely without throwing.
     */
    protected function emitSubmissionEventSafe(array $data): void
    {
        try {
            \App\Models\SubmissionEvent::create(array_merge([
                'occurred_at' => now(),
            ], $data));
        } catch (\Throwable $te) {
            Log::warning('Failed to write SubmissionEvent (helper)', [
                'submission_uuid' => $data['submission_uuid'] ?? 'unknown',
                'status' => $data['status'] ?? 'unknown',
                'error' => $te->getMessage(),
            ]);
        }
    }
    /**
     * Refund a transaction
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function refund(Request $request, $id)
    {
        // Enforce POS-only refunds via Sanctum-authenticated PosTerminal
        $posTerminal = $request->user();
        if (!$posTerminal || !($posTerminal instanceof PosTerminal)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Refunds are only permitted from POS terminals',
            ], 403);
        }

        $transaction = Transaction::find($id);
        if (!$transaction || (int) $transaction->terminal_id !== (int) $posTerminal->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found or does not belong to this terminal',
            ], 404);
        }

        // Business rule: only allow refunds on the same business day (configurable timezone)
        try {
            $tz = config('app.business_timezone', config('app.timezone', 'UTC'));
            $txTime = Carbon::parse($transaction->transaction_timestamp)->setTimezone($tz);
            $today = now()->setTimezone($tz);
            if ($txTime->toDateString() !== $today->toDateString()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Refunds are only permitted on the same business day',
                ], 409);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to validate refund timing',
            ], 500);
        }

        $refundData = $request->validate([
            'refund_amount' => 'required|numeric|min:0.01',
            'refund_reason' => 'required|string',
            'refund_reference_id' => 'nullable|string',
        ]);
        $refundData['refund_status'] = 'REFUNDED';
        $refundData['refund_processed_at'] = now();

        try {
            $service = app(\App\Services\TransactionService::class);
            $service->processRefund($transaction, $refundData);
            return response()->json(['status' => 'success', 'transaction' => $transaction]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
    /**
     * The NotificationService instance used to handle notification-related operations.
     *
     * @var NotificationService
     */
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        // Extend NotificationService to handle terminal callback notifications
        $this->notificationService = app(NotificationService::class);
    }

    /**
     * Validate that all required fields are present in the transaction array.
     *
     * @param array $transaction
     * @return bool
     */
    private function validateRequiredFields(array $transaction): bool
    {
        $requiredFields = [
            'transaction_id',
            'transaction_timestamp',
            'gross_sales',
            'payload_checksum'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($transaction[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Process adjustments and taxes for a transaction.
     *
     * @param \App\Models\Transaction $transactionModel
     * @param array $transaction
     * @return void
     */
    private function processAdjustmentsAndTaxes($transactionModel, array $transaction): void
    {
        // Process adjustments if present
        if (isset($transaction['adjustments']) && is_array($transaction['adjustments'])) {
            foreach ($transaction['adjustments'] as $adjustment) {
                // Use relation create to ensure the child record is linked by transaction_pk
                $transactionModel->adjustments()->create([
                    'adjustment_type' => $adjustment['adjustment_type'],
                    'amount' => $adjustment['amount'],
                ]);
            }
        }

        // Process taxes if present
        if (isset($transaction['taxes']) && is_array($transaction['taxes'])) {
            foreach ($transaction['taxes'] as $tax) {
                // Use relation create to ensure the child record is linked by transaction_pk
                $transactionModel->taxes()->create([
                    'tax_type' => $tax['tax_type'],
                    'amount' => $tax['amount'],
                ]);
            }
        }
    }


    /**
     * Notifies a terminal of the result of a transaction validation.
     *
     * This method checks if terminal notifications are enabled and a callback URL is available.
     * If so, it creates and sends a notification to the terminal via webhook, logging the event.
     * If notifications are not enabled or no callback URL is configured, a warning is logged.
     * Any exceptions during notification are caught and logged as errors.
     *
     * @param array $transactionData      The transaction data, including terminal and transaction IDs.
     * @param string $validationResult    The result of the transaction validation (e.g., 'success', 'failed').
     * @param array $validationErrors     Optional array of validation errors, if any.
     * @param string|null $terminalCallbackUrl Optional terminal callback URL to override the default.
     *
     * @return void
     */
    public function notifyTerminalOfValidationResult(
        array $transactionData,
        string $validationResult,
        array $validationErrors = [],
        ?string $terminalCallbackUrl = null
    ): void {
        try {
            // Get terminal and check if notifications are enabled
            $notificationsEnabled = true;
            if (!$terminalCallbackUrl && isset($transactionData['terminal_id'])) {
                $terminal = \App\Models\PosTerminal::find($transactionData['terminal_id']);
                $terminalCallbackUrl = $terminal->callback_url ?? null;
                $notificationsEnabled = $terminal->notifications_enabled ?? true;
            }

            // Create and send notification if enabled and URL exists
            if ($terminalCallbackUrl && $notificationsEnabled) {
                $notification = new \App\Notifications\TransactionResultNotification(
                    $transactionData,
                    $validationResult,
                    $validationErrors,
                    $terminalCallbackUrl
                );

                // Send to system (will trigger webhook and database logging)
                \Illuminate\Support\Facades\Notification::route('webhook', $terminalCallbackUrl)
                    ->notify($notification);

                Log::info('Terminal notification queued for transaction validation result', [
                    'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                    'validation_result' => $validationResult,
                    'terminal_callback_url' => $terminalCallbackUrl,
                ]);
            } else {
                Log::warning('No callback URL configured for terminal notification', [
                    'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                    'terminal_id' => $transactionData['terminal_id'] ?? 'unknown',
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to queue terminal notification for transaction result', [
                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send batch result notification to POS terminal
     */
    /**
     * Notifies a POS terminal of the result of a batch transaction processing.
     *
     * This method sends a notification to the terminal's configured callback URL if notifications are enabled.
     * The notification includes details about the batch, such as counts of processed and failed transactions,
     * overall status, and tenant/customer information. If notifications are not enabled or no callback URL is set,
     * an informational log is written instead. Errors during notification are logged as well.
     *
     * @param string $batchId The unique identifier for the batch.
     * @param PosTerminal $terminal The POS terminal to notify.
     * @param int $processedCount The number of successfully processed transactions.
     * @param int $failedCount The number of failed transactions.
     * @param array $processedTransactions List of successfully processed transactions.
     * @param array $failedTransactions List of failed transactions.
     *
     * @return void
     */
    public function notifyTerminalOfBatchResult(
        string $batchId,
        PosTerminal $terminal,
        int $processedCount,
        int $failedCount,
        array $processedTransactions,
        array $failedTransactions
    ): void {
        try {
            if (config('notifications.callbacks.enabled') && $terminal->notifications_enabled && $terminal->callback_url) {
                // Create batch result payload
                $batchData = [
                    'batch_id' => $batchId,
                    'terminal_id' => $terminal->id,
                    'processed_at' => now()->toISOString(),
                    'total_count' => $processedCount + $failedCount,
                    'success_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'overall_status' => $failedCount > 0 ? 'PARTIAL' : 'SUCCESS',
                    'tenant_id' => $terminal->tenant_id,
                    'customer_code' => $terminal->tenant->company->customer_code ?? 'UNKNOWN',
                ];

                // Create and send notification
                $notification = new \App\Notifications\TransactionResultNotification(
                    $batchData,
                    $failedCount > 0 ? 'PARTIAL' : 'VALID',
                    $failedCount > 0 ? ['failed_transactions' => $failedTransactions] : [],
                    $terminal->callback_url
                );

                // Send to system (will trigger webhook and database logging)
                \Illuminate\Support\Facades\Notification::route('webhook', $terminal->callback_url)
                    ->notify($notification);

                Log::info('Terminal batch notification queued', [
                    'batch_id' => $batchId,
                    'terminal_id' => $terminal->id,
                    'success_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'terminal_callback_url' => $terminal->callback_url,
                ]);
            } else {
                Log::info('Terminal notifications not enabled or no callback URL configured', [
                    'batch_id' => $batchId,
                    'terminal_id' => $terminal->id,
                    'notifications_enabled' => $terminal->notifications_enabled,
                    'has_callback_url' => !empty($terminal->callback_url),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send batch notification to terminal', [
                'batch_id' => $batchId,
                'terminal_id' => $terminal->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Store a newly created transaction in storage.
     *
     * Handles the incoming request to create a new transaction record.
     *
     * @param \Illuminate\Http\Request $request The HTTP request containing transaction data.
     * @return \Illuminate\Http\Response
     */
    /**
     * Legacy basic transaction ingestion endpoint (DEPRECATED).
     *
     * This endpoint has been disabled in favor of storeOfficial() which
     * enforces the canonical TSMS submission contract (submission_uuid,
     * strong checksum semantics, batch capability, richer validation & idempotency).
     *
     * Retained only as a stub to prevent accidental routing to the removed
     * implementation. If a route still points here it will return HTTP 410.
     */
    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Deprecated endpoint. Use the official submission endpoint (storeOfficial).'
        ], 410);
    }

    // ---------------------------------------------------------------------
    // Legacy implementation (commented out for backup/reference). Remove once
    // all external clients have migrated to storeOfficial().
    // ---------------------------------------------------------------------
    // public function store(Request $request)
    // {
    //     // Original full implementation preserved in VCS history.
    // }

    public function batchStore(Request $request)
    {
        try {
            DB::beginTransaction();

            Log::info('Batch transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'batch_id' => $request->batch_id ?? 'missing',
                'transaction_count' => count($request->transactions ?? [])
            ]);

            // Validate batch request structure (lenient to support test payloads)
            $request->validate([
                'tenant_id' => 'required|integer|exists:tenants,id',
                'terminal_id' => 'required|integer|exists:pos_terminals,id',
                'transactions' => 'required|array|min:1',
                'transactions.*.transaction_id' => 'required|string',
                // Accept either gross_sales/transaction_timestamp or amount/occurred_at
                'transactions.*.gross_sales' => 'nullable|numeric|min:0',
                'transactions.*.transaction_timestamp' => 'nullable|date',
                'transactions.*.amount' => 'nullable|numeric|min:0',
                'transactions.*.occurred_at' => 'nullable|date',
                'transactions.*.tenant_id' => 'nullable|integer',
            ]);

            $terminal = PosTerminal::findOrFail($request->terminal_id);

            // Ensure terminal belongs to the specified tenant to prevent cross-mapping
            if ((int) $terminal->tenant_id !== (int) $request->tenant_id) {
                Log::warning('batchStore: Terminal does not belong to the specified tenant', [
                    'declared_tenant_id' => $request->tenant_id,
                    'terminal_id' => $terminal->id,
                    'terminal_tenant_id' => $terminal->tenant_id,
                    'batch_id' => $request->batch_id ?? 'missing',
                ]);
                // Structured log for tenant/terminal mismatch
                try {
                    \App\Models\SystemLog::create([
                        'type' => 'transaction',
                        'log_type' => 'TENANT_TERMINAL_MISMATCH',
                        'severity' => 'error',
                        'terminal_uid' => $terminal->serial_number ?? null,
                        'transaction_id' => null,
                        'message' => 'Terminal does not belong to the specified tenant',
                        'context' => [
                            'declared_tenant_id' => $request->tenant_id,
                            'terminal_tenant_id' => $terminal->tenant_id,
                            'terminal_id' => $terminal->id,
                            'batch_id' => $request->batch_id ?? 'missing',
                            'endpoint' => 'transactions.batch.store',
                        ],
                    ]);
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to write SystemLog for TENANT_TERMINAL_MISMATCH', [
                        'terminal_id' => $terminal->id,
                        'error' => $logEx->getMessage(),
                    ]);
                }
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['tenant_id' => ['Terminal does not belong to the specified tenant']]
                ], 422);
            }
            // Customer code tenant-binding policy: warn or reject based on config
            $strictCustomerCode = (bool) config('tsms.validation.strict_customer_code_binding', false);
            $declaredCustomer = $request->transaction_count === 1
                ? ($request->transaction['customer_code'] ?? null)
                : null; // For batch we check inside loop per item
            $tenantCustomer = optional($terminal->tenant->company)->customer_code;
            if ($request->transaction_count === 1 && $declaredCustomer && $tenantCustomer && $declaredCustomer !== $tenantCustomer) {
                if ($strictCustomerCode) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Customer code mismatch with tenant',
                        'errors' => ['customer_code' => ['Customer code does not match tenant company']]
                    ], 422);
                } else {
                    Log::warning('Customer code mismatch with tenant (warn mode)', [
                        'submission_uuid' => $request->submission_uuid,
                        'declared_customer_code' => $declaredCustomer,
                        'tenant_customer_code' => $tenantCustomer,
                    ]);
                }
            }
            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;

            foreach ($request->transactions as $transactionData) {
                Log::info('Processing transaction', [
                    'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                    'tenant_id' => $transactionData['tenant_id'] ?? 'missing',
                    'terminal_id' => $request->terminal_id,
                    'transaction_timestamp' => $transactionData['transaction_timestamp'] ?? $transactionData['occurred_at'] ?? null,
                ]);

                // If item-level tenant_id is present, it must match request tenant_id
                if (isset($transactionData['tenant_id']) && (int) $transactionData['tenant_id'] !== (int) $request->tenant_id) {
                    Log::warning('Transaction tenant_id mismatch', [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'expected_tenant_id' => $request->tenant_id,
                        'actual_tenant_id' => $transactionData['tenant_id'],
                    ]);

                    // Structured log for per-item tenant mismatch
                    try {
                        \App\Models\SystemLog::create([
                            'type' => 'transaction',
                            'log_type' => 'TRANSACTION_TENANT_MISMATCH',
                            'severity' => 'error',
                            'terminal_uid' => $terminal->serial_number ?? null,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'message' => 'Transaction tenant_id does not match batch tenant_id',
                            'context' => [
                                'batch_id' => $request->batch_id ?? 'missing',
                                'batch_tenant_id' => $request->tenant_id,
                                'transaction_tenant_id' => $transactionData['tenant_id'],
                                'terminal_id' => $terminal->id,
                                'transaction_timestamp' => $transactionData['transaction_timestamp'] ?? $transactionData['occurred_at'] ?? null,
                                'endpoint' => 'transactions.batch.store',
                            ],
                        ]);
                    } catch (\Throwable $logEx) {
                        Log::warning('Failed to write SystemLog for TRANSACTION_TENANT_MISMATCH', [
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'error' => $logEx->getMessage(),
                        ]);
                    }

                    $failedTransactions[] = [
                        'transaction_id' => $transactionData['transaction_id'] ?? null,
                        'status' => 'failed',
                        'reason' => 'Tenant ID mismatch',
                    ];
                    $failedCount++;
                    continue; // Skip processing this transaction
                }

                try {
                    // Per-item customer_code tenant-binding
                    if (isset($transactionData['customer_code'])) {
                        $strictCustomerCode = (bool) config('tsms.validation.strict_customer_code_binding', false);
                        $tenantCustomer = optional($terminal->tenant->company)->customer_code;
                        if ($tenantCustomer && $transactionData['customer_code'] !== $tenantCustomer) {
                            if ($strictCustomerCode) {
                                $failedTransactions[] = [
                                    'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                    'status' => 'failed',
                                    'message' => 'Customer code mismatch with tenant',
                                ];
                                $failedCount++;
                                continue;
                            } else {
                                Log::warning('Customer code mismatch with tenant (warn mode, batch item)', [
                                    'batch_id' => $request->batch_id ?? 'missing',
                                    'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                    'declared_customer_code' => $transactionData['customer_code'],
                                    'tenant_customer_code' => $tenantCustomer,
                                ]);
                            }
                        }
                    }
                    // Optional per-item guard: if transaction payload includes tenant_id, it must match terminal's tenant
                    if (isset($transactionData['tenant_id']) && (int) $transactionData['tenant_id'] !== (int) $terminal->tenant_id) {
                        Log::warning('batchStore: Tenant ID mismatch in transaction item', [
                            'payload_tenant_id' => $transactionData['tenant_id'],
                            'terminal_tenant_id' => $terminal->tenant_id,
                            'terminal_id' => $terminal->id,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'batch_id' => $request->batch_id ?? 'missing',
                        ]);
                        try {
                            \App\Models\SystemLog::create([
                                'type' => 'transaction',
                                'log_type' => 'TRANSACTION_TENANT_MISMATCH',
                                'severity' => 'error',
                                'terminal_uid' => $terminal->serial_number ?? null,
                                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                'message' => 'Transaction tenant_id does not match terminal tenant',
                                'context' => [
                                    'batch_id' => $request->batch_id ?? 'missing',
                                    'transaction_tenant_id' => $transactionData['tenant_id'],
                                    'terminal_tenant_id' => $terminal->tenant_id,
                                    'terminal_id' => $terminal->id,
                                    'transaction_timestamp' => $transactionData['transaction_timestamp'] ?? $transactionData['occurred_at'] ?? null,
                                    'endpoint' => 'transactions.batch.store',
                                ],
                            ]);
                        } catch (\Throwable $logEx) {
                            Log::warning('Failed to write SystemLog for TRANSACTION_TENANT_MISMATCH (terminal)', [
                                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                'error' => $logEx->getMessage(),
                            ]);
                        }
                        $failedTransactions[] = [
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'status' => 'failed',
                            'message' => 'Tenant ID mismatch: transaction tenant does not match terminal tenant'
                        ];
                        $failedCount++;
                        continue; // skip this item but continue the batch
                    }
                    // Check for duplicate transaction
                    $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                        ->where('terminal_id', $terminal->id)
                        ->first();

                    if ($existingTransaction) {
                        $processedTransactions[] = [
                            'transaction_id' => $existingTransaction->transaction_id,
                            'status' => 'duplicate',
                            'message' => 'Transaction already exists'
                        ];
                        // Update terminal activity on duplicate to reflect recent sales interaction
                        try {
                            $terminal->last_seen_at = now();
                            if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                                $terminal->last_sale_at = now();
                            }
                            $terminal->save();
                        } catch (\Throwable $te) {
                            Log::warning('Failed to update terminal last_seen_at on duplicate transaction', [
                                'terminal_id' => $terminal->id,
                                'error' => $te->getMessage(),
                            ]);
                        }
                        continue;
                    }

                    // Aggregate taxes and adjustments from incoming payload so we persist canonical totals
                    $vatableSales = 0;
                    $vatAmount = 0;
                    $scVatExemptSales = 0;
                    if (isset($transactionData['taxes']) && is_array($transactionData['taxes'])) {
                        foreach ($transactionData['taxes'] as $tax) {
                            $taxType = strtoupper($tax['tax_type'] ?? '');
                            if ($taxType === 'VATABLE_SALES') {
                                $vatableSales += $tax['amount'] ?? 0;
                            } elseif ($taxType === 'VAT' || $taxType === 'VAT_AMOUNT') {
                                $vatAmount += $tax['amount'] ?? 0;
                            } elseif ($taxType === 'SC_VAT_EXEMPT_SALES' || $taxType === 'VAT-EXEMPT' || $taxType === 'EXEMPT' || $taxType === 'VATEXEMPT') {
                                $scVatExemptSales += $tax['amount'] ?? 0;
                            }
                        }
                    }

                    $promoDiscount = 0;
                    $seniorDiscount = 0;
                    $pwdDiscount = 0;
                    if (isset($transactionData['adjustments']) && is_array($transactionData['adjustments'])) {
                        foreach ($transactionData['adjustments'] as $adj) {
                            $type = strtolower($adj['adjustment_type'] ?? '');
                            $amt = $adj['amount'] ?? 0;
                            if ($type === 'promo_discount') {
                                $promoDiscount += $amt;
                            } elseif ($type === 'senior_discount') {
                                $seniorDiscount += $amt;
                            } elseif ($type === 'pwd_discount') {
                                $pwdDiscount += $amt;
                            }
                        }
                    }

                    // Normalize alternate field names used by tests
                    $normalizedTimestamp = $transactionData['transaction_timestamp']
                        ?? $transactionData['occurred_at']
                        ?? now()->toISOString();
                    // Convert ISO8601 to canonical DB-friendly ISO string with 3ms and trailing Z
                    try {
                        // FIX: Explicitly parse using application timezone and shift to it if necessary
                        // handle terminals sending local time with a 'Z' (signifying UTC) incorrectly
                        $dt = Carbon::parse($normalizedTimestamp, config('app.timezone'));
                        if (str_ends_with(strtoupper($normalizedTimestamp), 'Z')) {
                            $dt->shiftTimezone(config('app.timezone'));
                        }
                        // Ensure we are in UTC before formatting with Z for storage
                        $normalizedTimestampDb = $dt->utc()->format('Y-m-d\\TH:i:s.v\\Z');
                    } catch (\Throwable $t) {
                        $dt = now();
                        $micro = $dt->format('u');
                        $ms = str_pad(substr($micro, 0, 3), 3, '0', STR_PAD_RIGHT);
                        $normalizedTimestampDb = $dt->format('Y-m-d\\TH:i:s') . '.' . $ms . 'Z';
                    }
                    $normalizedGross = $transactionData['gross_sales']
                        ?? $transactionData['amount']
                        ?? 0;

                    // Create transaction record
                    $txPayload = [
                        'tenant_id' => $terminal->tenant_id,
                        'terminal_id' => $terminal->id,
                        'transaction_id' => $transactionData['transaction_id'],
                        'hardware_id' => $transactionData['hardware_id'] ?? null,
                        'transaction_timestamp' => $normalizedTimestampDb,
                        'gross_sales' => $normalizedGross,
                        'net_sales' => $transactionData['net_sales'] ?? 0,
                        'customer_code' => $transactionData['customer_code'] ?? ($terminal->tenant->company->customer_code ?? 'UNKNOWN'),
                        'payload_checksum' => $transactionData['payload_checksum'] ?? md5(json_encode($transactionData)),
                        'receipt_no' => $transactionData['receipt_no'] ?? null,
                        // If we are in accept-with-issues mode, mark created transactions accordingly
                        'validation_status' => ($acceptWithIssues ?? false) ? 'WITH_ISSUES' : 'PENDING',
                        'vatable_sales' => $vatableSales,
                        'vat_amount' => $vatAmount,
                        'sc_vat_exempt_sales' => $scVatExemptSales,
                    ];

                    if (Schema::hasColumn('transactions', 'promo_discount')) {
                        $txPayload['promo_discount'] = $promoDiscount;
                    }
                    if (Schema::hasColumn('transactions', 'senior_discount')) {
                        $txPayload['senior_discount'] = $seniorDiscount;
                    }
                    if (Schema::hasColumn('transactions', 'pwd_discount')) {
                        $txPayload['pwd_discount'] = $pwdDiscount;
                    }

                    // Create the transaction; if a race condition causes a duplicate key,
                    // treat it as idempotent success rather than failure.
                    try {
                        $transaction = Transaction::create($txPayload);
                    } catch (\Illuminate\Database\QueryException $qe) {
                        // SQLSTATE 23000 is integrity constraint violation (duplicate key)
                        $sqlState = $qe->getCode();
                        $message = $qe->getMessage();
                        if ($sqlState === '23000' || str_contains($message, 'Integrity constraint violation') || str_contains($message, 'Duplicate entry')) {
                            \Log::info('storeOfficial: Duplicate transaction detected at insert (treating as idempotent)', [
                                'transaction_id' => $transactionData['transaction_id'],
                                'terminal_id' => $terminal->id,
                                'error' => $message,
                            ]);

                            $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                                ->where('terminal_id', $terminal->id)
                                ->first();

                            if ($existingTransaction) {
                                // Structured log for idempotent transaction replay in batch ingest
                                try {
                                    \App\Models\SystemLog::create([
                                        'type' => 'transaction',
                                        'log_type' => 'BATCH_TRANSACTION_IDEMPOTENT_REPLAY',
                                        'severity' => 'info',
                                        'terminal_uid' => $terminal->serial_number ?? null,
                                        'transaction_id' => $existingTransaction->transaction_id,
                                        'message' => 'Duplicate transaction treated as idempotent in batch ingest',
                                        'context' => [
                                            'batch_id' => $request->batch_id ?? 'missing',
                                            'tenant_id' => $terminal->tenant_id,
                                            'terminal_id' => $terminal->id,
                                            'endpoint' => 'transactions.batch.store',
                                        ],
                                    ]);
                                } catch (\Throwable $logEx) {
                                    Log::warning('Failed to write SystemLog for BATCH_TRANSACTION_IDEMPOTENT_REPLAY', [
                                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                        'error' => $logEx->getMessage(),
                                    ]);
                                }

                                $processedTransactions[] = [
                                    'transaction_id' => $existingTransaction->transaction_id,
                                    'status' => 'success',
                                    'message' => 'Transaction already processed'
                                ];

                                // Update terminal activity for idempotent transaction replay
                                try {
                                    $terminal->last_seen_at = now();
                                    if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                                        $terminal->last_sale_at = now();
                                    }
                                    $terminal->save();
                                } catch (\Throwable $te) {
                                    Log::warning('Failed to update terminal last_seen_at after idempotent insert duplicate', [
                                        'terminal_id' => $terminal->id,
                                        'transaction_id' => $existingTransaction->transaction_id,
                                        'error' => $te->getMessage(),
                                    ]);
                                }

                                continue; // proceed to next item
                            }

                            // If for some reason we can't find it, rethrow to be handled by outer catch
                        }
                        throw $qe;
                    }

                    // Queue the transaction for processing
                    // Shard queue by tenant for fairness
                    $shard = $terminal->tenant_id % 8; // 8 shards
                    ProcessTransactionJob::dispatch($transaction->id)
                        ->onQueue('transaction-processing:s' . $shard)
                        ->afterCommit();

                    // Log system activity
                    \App\Models\SystemLog::create([
                        'type' => 'transaction',
                        'severity' => 'info',
                        'terminal_uid' => $terminal->serial_number,
                        'transaction_id' => $transaction->transaction_id,
                        'message' => 'Batch transaction queued for processing',
                        'context' => json_encode([
                            'batch_id' => $request->batch_id,
                            'transaction_id' => $transaction->transaction_id,
                            'gross_sales' => $transaction->gross_sales,
                            'net_sales' => $transaction->net_sales,
                            'transaction_timestamp' => $transaction->transaction_timestamp,
                        ])
                    ]);

                    $processedTransactions[] = [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => 'queued',
                        'message' => 'Transaction queued for processing'
                    ];
                    $processedCount++;

                    // Update terminal activity on successful creation
                    try {
                        $terminal->last_seen_at = now();
                        if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                            $terminal->last_sale_at = now();
                        }
                        $terminal->save();
                    } catch (\Throwable $te) {
                        Log::warning('Failed to update terminal last_seen_at after transaction creation', [
                            'terminal_id' => $terminal->id,
                            'transaction_id' => $transaction->transaction_id,
                            'error' => $te->getMessage(),
                        ]);
                    }

                    Log::info('Transaction processed successfully', [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                    ]);

                } catch (\Exception $e) {
                    Log::error('Failed to process transaction in batch', [
                        'batch_id' => $request->batch_id,
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);

                    // Structured per-item failure log for batch ingestion (non-blocking)
                    try {
                        \App\Models\SystemLog::create([
                            'type' => 'transaction',
                            'log_type' => 'BATCH_TRANSACTION_INGESTION_FAILED',
                            'severity' => 'error',
                            'terminal_uid' => $terminal->serial_number ?? null,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'message' => 'Batch transaction failed during ingestion',
                            'context' => [
                                'batch_id' => $request->batch_id,
                                'tenant_id' => $request->tenant_id,
                                'terminal_id' => $request->terminal_id,
                                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                'endpoint' => 'transactions.batch.store',
                                'error_message' => $e->getMessage(),
                                'payload_checksum' => $transactionData['payload_checksum'] ?? null,
                            ],
                        ]);
                    } catch (\Throwable $logEx) {
                        Log::warning('Failed to write SystemLog for BATCH_TRANSACTION_INGESTION_FAILED', [
                            'batch_id' => $request->batch_id,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'error' => $logEx->getMessage(),
                        ]);
                    }

                    $failedTransactions[] = [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];
                    $failedCount++;
                }
            }

            DB::commit();

            // If we previously accepted this submission despite checksum issues,
            // emit an ACCEPTED_WITH_ISSUES submission event for audit/triage purposes.
            if (!empty($deferredAcceptedWithIssues) && is_array($deferredAcceptedWithIssues)) {
                try {
                    \App\Models\SubmissionEvent::create($deferredAcceptedWithIssues);
                    Log::info('SubmissionEvent created (ACCEPTED_WITH_ISSUES)', [
                        'submission_uuid' => $deferredAcceptedWithIssues['submission_uuid'] ?? null,
                        'correlation_id' => $deferredAcceptedWithIssues['correlation_id'] ?? null,
                    ]);
                } catch (\Throwable $te) {
                    Log::warning('Failed to write SubmissionEvent (ACCEPTED_WITH_ISSUES)', [
                        'submission_uuid' => $deferredAcceptedWithIssues['submission_uuid'] ?? null,
                        'error' => $te->getMessage(),
                    ]);
                }
            }

            Log::info('Batch transaction processing completed', [
                'batch_id' => $request->batch_id,
                'processed_count' => $processedCount,
                'failed_count' => $failedCount
            ]);

            // Send notification if there are batch failures
            if ($failedCount > 0 && !empty($request->batch_id)) {
                $this->notificationService->notifyBatchProcessingFailure(
                    (string) $request->batch_id,
                    count($request->transactions),
                    $failedTransactions
                );
            }

            return response()->json([
                'success' => true,
                'message' => "Batch processed: {$processedCount} successful, {$failedCount} failed",
                'processed' => $processedCount,
                'failed' => $failedCount,
                'data' => [
                    'batch_id' => $request->batch_id ?? null,
                    'processed_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'transactions' => array_merge($processedTransactions, $failedTransactions)
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            Log::warning('Batch transaction validation failed', [
                'errors' => $e->errors(),
                'batch_id' => $request->batch_id ?? null,
                'transaction_ids' => collect($request->transactions ?? [])->take(10)->pluck('transaction_id')->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Batch transaction API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['password', 'token']),
                'batch_id' => $request->batch_id ?? null,
                'transaction_ids' => collect($request->transactions ?? [])->take(10)->pluck('transaction_id')->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process batch transactions: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Unexpected error in batchStore', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
                'batch_id' => $request->batch_id ?? null,
                'transaction_ids' => collect($request->transactions ?? [])->take(10)->pluck('transaction_id')->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status($id)
    {
        $transaction = Transaction::where('transaction_id', $id)->first();
        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'transaction_id' => $transaction->transaction_id,
                'customer_code' => $transaction->customer_code,
                'gross_sales' => $transaction->gross_sales,
                'net_sales' => $transaction->net_sales,
                'status' => 'queued', // Default status for basic implementation
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString()
            ]
        ]);
    }

    /**
     * Void a transaction by transaction_id
     *
     * @param Request $request
     * @param string $transaction_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function void(Request $request, $transaction_id)
    {
        $request->validate([
            'void_reason' => 'required|string|max:255',
        ]);

        $transaction = Transaction::where('transaction_id', $transaction_id)->first();
        if ($transaction) {
            // Ensure tenant_id and terminal_id are loaded
            $tenant_id = $transaction->tenant_id ?? null;
            $terminal_id = $transaction->terminal_id ?? ($transaction->serial_number ? \App\Models\PosTerminal::where('serial_number', $transaction->serial_number)->value('id') : null);
        }
        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        if ($transaction->voided_at) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction already voided',
                'voided_at' => $transaction->voided_at,
                'void_reason' => $transaction->void_reason
            ], 409);
        }


        $transaction->voided_at = now();
        $transaction->void_reason = $request->void_reason;
        $transaction->save();

        // Forward to webapp after voiding
        try {
            $forwardingService = app(\App\Services\WebAppForwardingService::class);
            // Set the endpoint for void transactions explicitly if needed
            if (method_exists($forwardingService, 'setEndpoint')) {
                $voidEndpoint = config('tsms.web_app.void_endpoint', env('WEBAPP_FORWARDING_VOID_ENDPOINT', 'https://tsms-ops.test/api/transactions/void'));
                $forwardingService->setEndpoint($voidEndpoint);
            }
            // Build payload with tenant_id and terminal_id
            $payload = [
                'transaction_id' => $transaction->transaction_id,
                'voided_at' => $transaction->voided_at,
                'void_reason' => $transaction->void_reason,
                'tenant_id' => $tenant_id,
                'terminal_id' => $terminal_id,
            ];
            if (method_exists($forwardingService, 'forwardVoidedTransaction')) {
                // Service expects a Transaction model instance so pass the saved model
                $forwardingService->forwardVoidedTransaction($transaction);
            } else {
                // Fallback: send via generic forward method using the prepared payload
                $forwardingService->forward($payload);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to forward voided transaction to webapp', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transaction voided successfully',
            'transaction_id' => $transaction->transaction_id,
            'voided_at' => $transaction->voided_at,
            'void_reason' => $transaction->void_reason
        ]);
    }

    /**
     * Void a transaction initiated by POS terminal
     *
     * @param Request $request
     * @param string $transaction_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function voidFromPOS(Request $request, $transaction_id)
    {
        try {
            DB::beginTransaction();

            // Accept either transaction_id (preferred) OR receipt_no for POS-initiated voids.
            $request->validate([
                'transaction_id' => 'nullable|string|uuid|max:191',
                'receipt_no' => 'nullable|string|max:128',
                'void_reason' => 'required|string|max:255',
                'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 required for POS requests
            ]);

            // Ensure at least one identifier supplied
            if (empty($request->transaction_id) && empty($request->receipt_no)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Either transaction_id or receipt_no is required',
                    'errors' => ['identifier' => ['Either transaction_id or receipt_no must be provided']]
                ], 422);
            }

            // Get authenticated terminal (from Sanctum middleware)
            $posTerminal = $request->user(); // This is the POS terminal making the request
            if (!$posTerminal) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - invalid terminal token'
                ], 401);
            }

            $tenantId = $posTerminal->tenant_id ?? null;

            // Determine if caller explicitly supplied transaction_id in the request body
            // Note: Laravel may surface route parameters as request input; avoid treating
            // the route param as an explicit body identifier to allow receipt_no-only requests.
            $requestBody = $request->all();
            $explicitTransactionId = array_key_exists('transaction_id', $requestBody) && !empty($requestBody['transaction_id']);

            // Only pass transaction_id into the lookup helper when it was explicitly provided
            // in the request body. This prevents the route parameter from forcing the
            // transaction_id lookup path when the client intended receipt_no lookup.
            $txIdForLookup = $explicitTransactionId ? $request->transaction_id : null;

            // Use model helper to find transaction by either identifier scoped to tenant+terminal
            $lookup = Transaction::findForVoidByTerminal($tenantId, $posTerminal->id, $txIdForLookup, $request->receipt_no ?? null);

            if ($lookup['ambiguous']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Ambiguous receipt_no — multiple transactions found',
                ], 409);
            }

            $transaction = $lookup['transaction'];
            $usedIdentifier = $lookup['identifier'];

            if (!$transaction) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found or does not belong to this terminal'
                ], 404);
            }

            // If transaction_id supplied, ensure it matches route parameter for security
            if (!empty($request->transaction_id) && $request->transaction_id !== $transaction_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction ID mismatch',
                    'errors' => ['transaction_id' => ['Request transaction_id must match the transaction being voided']]
                ], 422);
            }

            if ($transaction->voided_at) {
                // If already voided, treat identical requests as idempotent successes.
                // Recompute canonical checksum using stored values and compare with provided checksum.
                try {
                    $storedPayload = [];
                    if ($usedIdentifier === 'transaction_id') {
                        $storedPayload['transaction_id'] = $transaction->transaction_id;
                    } else {
                        $storedPayload['receipt_no'] = isset($transaction->receipt_no) ? trim((string) $transaction->receipt_no) : trim((string) ($request->receipt_no ?? ''));
                    }
                    $storedPayload['void_reason'] = $transaction->void_reason ?? '';

                    $checksumService = app(\App\Services\PayloadChecksumService::class);
                    $provided = $request->payload_checksum;
                    $storedChecksum = $checksumService->computeChecksum($storedPayload);
                    if (hash_equals($storedChecksum, $provided)) {
                        // Idempotent retry: return success with existing void details
                        DB::rollBack();
                        return response()->json([
                            'success' => true,
                            'message' => 'Transaction already voided (idempotent)',
                            'transaction_id' => $transaction->transaction_id,
                            'voided_at' => $transaction->voided_at,
                            'void_reason' => $transaction->void_reason
                        ], 200);
                    }
                } catch (\Throwable $e) {
                    // Fall through to conflict response if checksum comparison fails for any reason
                }

                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction already voided',
                    'voided_at' => $transaction->voided_at,
                    'void_reason' => $transaction->void_reason
                ], 409);
            }

            // Enhanced business rule validation
            if (isset($transaction->validation_status) && $transaction->validation_status === 'PROCESSING') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot void transaction currently being processed'
                ], 409);
            }

            // Compute checksum using the identifier actually used for lookup
            $checksumService = new \App\Services\PayloadChecksumService();
            $payloadForChecksum = [];
            if ($usedIdentifier === 'transaction_id') {
                $payloadForChecksum['transaction_id'] = $transaction->transaction_id;
            } else {
                // Use the DB-stored receipt_no when lookup resolved by receipt_no to
                // ensure canonicalization matches persistent value. Fall back to the
                // request-supplied value if DB value is missing for any reason.
                $dbReceipt = isset($transaction->receipt_no) ? trim((string) $transaction->receipt_no) : null;
                $payloadForChecksum['receipt_no'] = $dbReceipt ?? trim((string) $request->receipt_no);
            }
            $payloadForChecksum['void_reason'] = $request->void_reason;

            // Compute checksum and allow a few normalized fallbacks for receipt_no
            $expectedChecksum = $checksumService->computeChecksum($payloadForChecksum);
            $provided = $request->payload_checksum;

            // Always emit a deterministic debug line with the canonical payload and computed checksum
            // This ensures PHPUnit runs (even when app.debug is not set) will produce log entries we can inspect.
            try {
                Log::debug('checksum-canonical', [
                    'used_identifier' => $usedIdentifier,
                    'transaction_pk' => $transaction->id ?? null,
                    'transaction_id' => $transaction->transaction_id ?? null,
                    'terminal_id' => $posTerminal->id ?? null,
                    'payload_for_checksum' => $payloadForChecksum,
                    'computed_checksum' => $expectedChecksum,
                    'provided_checksum' => $provided,
                ]);
            } catch (\Throwable $logEx) {
                // Logging must never break the request flow — swallow and continue.
            }

            // Debugging: also keep the old conditional info log when app.debug is enabled
            if (config('app.debug')) {
                Log::info('checksum-debug', [
                    'used_identifier' => $usedIdentifier,
                    'computed' => $expectedChecksum,
                    'provided' => $provided,
                    'payload_for_checksum' => $payloadForChecksum,
                ]);
            }

            $checksumOk = hash_equals($expectedChecksum, $provided);
            if (!$checksumOk && $usedIdentifier === 'receipt_no') {
                // Try a few fallbacks: use DB-stored receipt_no, upper/lower case variants
                $variants = [];
                $reqReceipt = trim((string) ($request->receipt_no ?? ''));
                $dbReceipt = trim((string) ($transaction->receipt_no ?? ''));
                $variants[] = ['receipt_no' => $reqReceipt, 'void_reason' => $request->void_reason];
                $variants[] = ['receipt_no' => $dbReceipt, 'void_reason' => $request->void_reason];
                $variants[] = ['receipt_no' => strtoupper($reqReceipt), 'void_reason' => $request->void_reason];
                $variants[] = ['receipt_no' => strtolower($reqReceipt), 'void_reason' => $request->void_reason];

                foreach ($variants as $v) {
                    $candidate = $checksumService->computeChecksum($v);
                    if (hash_equals($candidate, $provided)) {
                        $checksumOk = true;
                        break;
                    }
                }
            }

            if (!$checksumOk) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payload checksum',
                    'errors' => ['payload_checksum' => ['Checksum validation failed']]
                ], 422);
            }

            // Business rule: disallow voiding if there are existing refunds for this transaction
            try {
                $hasRefunds = \App\Models\Transaction::where('original_transaction_id', $transaction->transaction_id)
                    ->where('transaction_type', 'REFUND')
                    ->exists();
            } catch (\Throwable $e) {
                // If the query fails for any reason, be conservative and assume no refunds exist
                $hasRefunds = false;
            }

            if ($hasRefunds) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot void a transaction that has refunds',
                ], 409);
            }

            // Business rule: only allow voids on the same business day (configurable timezone)
            try {
                $tz = config('app.business_timezone', config('app.timezone', 'UTC'));
                $txTime = Carbon::parse($transaction->transaction_timestamp)->setTimezone($tz);
                $today = now()->setTimezone($tz);
                if ($txTime->toDateString() !== $today->toDateString()) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Voids are only permitted on the same business day',
                    ], 409);
                }
            } catch (\Throwable $e) {
                // If timezone parsing fails, be conservative and reject the void
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to validate void timing',
                ], 500);
            }

            // Update transaction with void information and timestamp
            $voidedAt = now();
            $transaction->voided_at = $voidedAt;
            $transaction->void_reason = $request->void_reason;
            // Optionally record which reference was used (audit only) - not persisted by default
            $transaction->save();

            Log::info('Transaction voided successfully', [
                'transaction_id' => $transaction->transaction_id,
                'voided_at' => $voidedAt,
                'void_reason' => $request->void_reason,
                'initiated_by' => 'POS',
                'terminal_id' => $posTerminal->id,
                'used_identifier' => $usedIdentifier,
            ]);

            // Add system log entry
            try {
                \App\Models\SystemLog::create([
                    'type' => 'transaction',
                    'log_type' => 'TRANSACTION_VOID_POS',
                    'severity' => 'info',
                    'terminal_uid' => $posTerminal->serial_number,
                    'transaction_id' => $transaction->transaction_id,
                    'message' => 'Transaction voided by POS terminal',
                    'context' => json_encode([
                        'void_reason' => $request->void_reason,
                        'terminal_id' => $posTerminal->id,
                        'voided_at' => $voidedAt,
                        'initiated_by' => 'POS',
                        'used_identifier' => $usedIdentifier,
                        'request_transaction_id' => $request->transaction_id ?? null,
                        'request_receipt_no' => $request->receipt_no ?? null,
                    ])
                ]);
            } catch (\Exception $logError) {
                Log::warning('Failed to create system log for POS void', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            // Add audit log entry
            try {
                \App\Models\AuditLog::create([
                    'user_id' => optional(auth())->id(),
                    'ip_address' => request()->ip(),
                    'action' => 'TRANSACTION_VOID_POS',
                    'action_type' => 'TRANSACTION_VOID_POS',
                    'resource_type' => 'transaction',
                    'resource_id' => $transaction->transaction_id,
                    'auditable_type' => 'transaction',
                    'auditable_id' => $transaction->id,
                    'message' => 'Transaction voided by POS terminal',
                    'metadata' => [
                        'transaction_id' => $transaction->transaction_id,
                        'void_reason' => $request->void_reason,
                        'terminal_id' => $posTerminal->id,
                        'terminal_serial' => $posTerminal->serial_number,
                        'tenant_id' => $tenantId,
                        'initiated_by' => 'POS',
                        'voided_at' => $voidedAt,
                        'used_identifier' => $usedIdentifier,
                        'request_transaction_id' => $request->transaction_id ?? null,
                        'request_receipt_no' => $request->receipt_no ?? null,
                    ]
                ]);
            } catch (\Exception $logError) {
                Log::warning('Failed to create audit log for POS void', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            // Forward to webapp after voiding
            try {
                $forwardingService = app(\App\Services\WebAppForwardingService::class);
                if (method_exists($forwardingService, 'setEndpoint')) {
                    $voidEndpoint = config('tsms.web_app.void_endpoint', env('WEBAPP_FORWARDING_VOID_ENDPOINT', 'https://tsms-ops.test/api/transactions/void'));
                    $forwardingService->setEndpoint($voidEndpoint);
                }
                // If forwarding service expects model, pass it; else pass payload
                $payload = [
                    'transaction_id' => $transaction->transaction_id,
                    'voided_at' => $transaction->voided_at,
                    'void_reason' => $transaction->void_reason,
                    'tenant_id' => $tenantId,
                    'terminal_id' => $posTerminal->id,
                    'initiated_by' => 'POS',
                    'terminal_serial' => $posTerminal->serial_number,
                    'used_identifier' => $usedIdentifier,
                ];
                if (method_exists($forwardingService, 'forwardVoidedTransaction')) {
                    $forwardingService->forwardVoidedTransaction($transaction);
                } else {
                    $forwardingService->forward($payload);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to forward voided transaction to webapp', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transaction voided successfully by POS',
                'transaction_id' => $transaction->transaction_id,
                'voided_at' => $transaction->voided_at,
                'void_reason' => $transaction->void_reason
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('POS void transaction error', [
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'terminal_id' => isset($posTerminal) ? $posTerminal->id : 'unknown',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to void transaction: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Store transactions using the official TSMS payload format.
     * Supports both single transaction and batch submissions.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOfficial(TSMSTransactionRequest $request)
    {
        // Generate correlation ID for audit trail
        $correlationId = \Illuminate\Support\Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);

        try {
            DB::beginTransaction();

            Log::info('Official TSMS transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'submission_uuid' => $request->submission_uuid ?? 'missing',
                'transaction_count' => $request->transaction_count ?? 'missing',
                'correlation_id' => $correlationId
            ]);

            // Enforce terminal token -> terminal binding using Sanctum personal access tokens
            $bearer = $request->bearerToken();
            if (empty($bearer)) {
                Log::warning('storeOfficial: Missing Authorization bearer token');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - missing token'
                ], 401);
            }

            // Resolve token via Sanctum; this safely handles hashed tokens
            $personalToken = PersonalAccessToken::findToken($bearer);
            if (!$personalToken) {
                Log::warning('storeOfficial: Authorization token not found or invalid', ['terminal_id' => $request->terminal_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - invalid token'
                ], 401);
            }

            // Ensure token is issued to a PosTerminal and matches the terminal_id in the request
            $tokenableType = $personalToken->tokenable_type ?? null;
            $tokenableId = $personalToken->tokenable_id ?? null;

            if ($tokenableType !== \App\Models\PosTerminal::class || (int) $tokenableId !== (int) $request->terminal_id) {
                Log::warning('storeOfficial: Token does not belong to the declared terminal', [
                    'tokenable_type' => $tokenableType,
                    'tokenable_id' => $tokenableId,
                    'declared_terminal_id' => $request->terminal_id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden - token does not match terminal'
                ], 403);
            }

            // Check token expiry if set
            if (method_exists($personalToken, 'expires_at') && $personalToken->expires_at && $personalToken->expires_at->isPast()) {
                Log::warning('storeOfficial: Authorization token has expired', ['terminal_id' => $request->terminal_id]);

                // Structured log for expired terminal token
                try {
                    $terminalFromToken = $personalToken->tokenable;
                    \App\Models\SystemLog::create([
                        'type' => 'security',
                        'log_type' => 'TERMINAL_TOKEN_EXPIRED',
                        'severity' => 'medium',
                        'terminal_uid' => $terminalFromToken->serial_number ?? null,
                        'transaction_id' => null,
                        'message' => 'Terminal token expired for official submission request',
                        'context' => [
                            'terminal_id' => $terminalFromToken->id ?? $request->terminal_id,
                            'tenant_id' => $terminalFromToken->tenant_id ?? null,
                            'path' => $request->path(),
                            'submission_uuid' => $request->submission_uuid ?? null,
                        ],
                    ]);
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to write SystemLog for TERMINAL_TOKEN_EXPIRED', [
                        'terminal_id' => $request->terminal_id,
                        'error' => $logEx->getMessage(),
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - token expired'
                ], 401);
            }

            // Optional: ensure the terminal record is active
            $terminalFromToken = $personalToken->tokenable;
            if ($terminalFromToken && method_exists($terminalFromToken, 'isActiveAndValid') && !$terminalFromToken->isActiveAndValid()) {
                Log::warning('storeOfficial: Terminal associated with token is not active/valid', ['terminal_id' => $request->terminal_id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden - terminal inactive'
                ], 403);
            }

            // Check for empty request body
            if (empty($request->all())) {
                Log::warning('storeOfficial: Empty request body');
                return response()->json([
                    'success' => false,
                    'message' => 'Malformed JSON or empty request body'
                ], 400);
            }

            // CRITICAL FIX: Validate submission structure FIRST before any database operations
            $request->validate([
                'submission_uuid' => 'required|string|uuid',
                'tenant_id' => 'required|integer',
                'terminal_id' => 'required|integer|exists:pos_terminals,id',
                'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
                'transaction_count' => 'required|integer|min:1',
                'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 hash
            ]);

            Log::info('storeOfficial: Basic validation passed', [
                'submission_uuid' => $request->submission_uuid,
                'transaction_count' => $request->transaction_count
            ]);

            // Detailed structure validation (moved from TSMSTransactionRequest)
            $this->validateDetailedStructure($request, $correlationId);

            // ------------------------------------------------------------------
            // Submission-level idempotency & drift detection
            // ------------------------------------------------------------------
            $submission = \App\Models\TransactionSubmission::where('terminal_id', $request->terminal_id)
                ->where('submission_uuid', $request->submission_uuid)
                ->first();

            // ALSO check for existing transactions (comprehensive idempotency)
            $existingTransactions = \App\Models\Transaction::where('terminal_id', $request->terminal_id)
                ->where('submission_uuid', $request->submission_uuid)
                ->get();

            if ($submission || $existingTransactions->count() > 0) {
                // Handle submission envelope drift detection (if submission exists)
                if ($submission) {
                    $payloadDrift = strtolower($submission->payload_checksum) !== strtolower($request->payload_checksum);
                    $countMismatch = (int) $submission->transaction_count !== (int) $request->transaction_count;

                    if ($payloadDrift || $countMismatch) {
                        // Conflict: same terminal + submission_uuid BUT different payload characteristics
                        Log::warning('storeOfficial: Submission drift conflict detected', [
                            'submission_uuid' => $request->submission_uuid,
                            'terminal_id' => $request->terminal_id,
                            'original_checksum' => $submission->payload_checksum,
                            'incoming_checksum' => $request->payload_checksum,
                            'original_count' => $submission->transaction_count,
                            'incoming_count' => $request->transaction_count,
                        ]);
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Submission conflict (payload drift)',
                            'conflict' => [
                                'submission_uuid' => $request->submission_uuid,
                                'terminal_id' => $request->terminal_id,
                                'original' => [
                                    'payload_checksum' => $submission->payload_checksum,
                                    'transaction_count' => $submission->transaction_count,
                                ],
                                'incoming' => [
                                    'payload_checksum' => $request->payload_checksum,
                                    'transaction_count' => $request->transaction_count,
                                ]
                            ]
                        ], 409);
                    }
                }

                // Idempotent replay: return previously processed summary
                Log::info('storeOfficial: Idempotent replay detected (early check)', [
                    'submission_uuid' => $request->submission_uuid,
                    'terminal_id' => $request->terminal_id,
                    'submission_exists' => $submission ? true : false,
                    'transaction_rows' => $existingTransactions->count(),
                ]);

                // Structured log for idempotent official submission replay
                try {
                    \App\Models\SystemLog::create([
                        'type' => 'transaction',
                        'log_type' => 'OFFICIAL_SUBMISSION_IDEMPOTENT_REPLAY',
                        'severity' => 'info',
                        'terminal_uid' => $terminalFromToken instanceof \App\Models\PosTerminal ? $terminalFromToken->serial_number : null,
                        'transaction_id' => null,
                        'message' => 'Official submission already processed (idempotent replay)',
                        'context' => [
                            'submission_uuid' => $request->submission_uuid,
                            'tenant_id' => $terminalFromToken->tenant_id ?? null,
                            'terminal_id' => $request->terminal_id,
                            'transaction_count' => $submission ? $submission->transaction_count : $existingTransactions->count(),
                            'endpoint' => 'transactions.official.store',
                        ],
                    ]);
                } catch (\Throwable $logEx) {
                    Log::warning('Failed to write SystemLog for OFFICIAL_SUBMISSION_IDEMPOTENT_REPLAY', [
                        'submission_uuid' => $request->submission_uuid,
                        'error' => $logEx->getMessage(),
                    ]);
                }
                // Update terminal liveness on idempotent replay
                try {
                    if ($terminalFromToken instanceof \App\Models\PosTerminal) {
                        $terminalFromToken->last_seen_at = now();
                        $terminalFromToken->save();
                    }
                } catch (\Throwable $te) {
                    Log::warning('Failed to update terminal last_seen_at on idempotent replay', [
                        'terminal_id' => $request->terminal_id,
                        'error' => $te->getMessage(),
                    ]);
                }

                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Submission already processed (idempotent)',
                    'data' => [
                        'submission_uuid' => $request->submission_uuid,
                        'transaction_count' => $submission ? $submission->transaction_count : $existingTransactions->count(),
                        'status' => $submission ? $submission->status : 'COMPLETED',
                        'transactions' => $existingTransactions->pluck('transaction_id'),
                    ]
                ], 200);
            }

            // Validate either single transaction or batch format
            if ($request->transaction_count === 1) {
                $request->validate([
                    'transaction' => 'required|array',
                    'transaction.transaction_id' => 'required|string|uuid',
                    'transaction.transaction_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
                    'transaction.gross_sales' => 'required|numeric',
                    'transaction.net_sales' => 'required|numeric',
                    'transaction.promo_status' => 'required|string',
                    'transaction.receipt_no' => 'nullable|string|max:128',
                    'transaction.customer_code' => 'required|string',
                    'transaction.payload_checksum' => 'required|string|min:64|max:64',
                    'transaction.adjustments' => 'required|array|min:7',
                    'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                    'transaction.adjustments.*.amount' => 'required_with:transaction.adjustments|numeric',
                    'transaction.taxes' => 'required|array|min:4',
                    'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                    'transaction.taxes.*.amount' => 'required_with:transaction.taxes|numeric',
                ]);
            } else {
                $request->validate([
                    'transactions' => 'required|array|min:1',
                    'transactions.*.transaction_id' => 'required|string|uuid',
                    'transactions.*.transaction_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
                    'transactions.*.gross_sales' => 'required|numeric',
                    'transactions.*.net_sales' => 'required|numeric',
                    'transactions.*.promo_status' => 'required|string',
                    'transactions.*.receipt_no' => 'nullable|string|max:128',
                    'transactions.*.customer_code' => 'required|string',
                    'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                    'transactions.*.adjustments' => 'required|array|min:7',
                    'transactions.*.adjustments.*.adjustment_type' => 'required_with:transactions.*.adjustments|string',
                    'transactions.*.adjustments.*.amount' => 'required_with:transactions.*.adjustments|numeric',
                    'transactions.*.taxes' => 'required|array|min:4',
                    'transactions.*.taxes.*.tax_type' => 'required_with:transactions.*.taxes|string',
                    'transactions.*.taxes.*.amount' => 'required_with:transactions.*.taxes|numeric',
                ]);
            }

            // Validate transaction count matches actual count
            $actualCount = $request->transaction_count === 1 ? 1 : count($request->transactions);
            if ($actualCount !== $request->transaction_count) {
                // Emit submission-level REJECTED for count mismatch
                $this->emitSubmissionEventSafe([
                    'submission_uuid' => $request->submission_uuid ?? 'unknown',
                    'tenant_id' => $request->tenant_id ?? null,
                    'terminal_id' => $request->terminal_id ?? null,
                    'status' => 'REJECTED',
                    'reason_code' => 'COUNT_MISMATCH',
                    'reason_details' => [
                        'expected' => (int) ($request->transaction_count ?? 0),
                        'actual' => (int) $actualCount,
                    ],
                    'transaction_count' => (int) ($request->transaction_count ?? 0),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ]);
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction count mismatch',
                    'errors' => ['transaction_count' => ["Expected {$request->transaction_count} transactions, got {$actualCount}"]]
                ], 422);
            }

            // Validate payload checksums using raw JSON for canonicalization
            $rawPayload = $request->getContent();
            $checksumService = new PayloadChecksumService();
            $checksumResults = $checksumService->validateSubmissionChecksumsFromRaw($rawPayload);
            $correlationId = $request->attributes->get('correlation_id');
            // Feature toggle: when enabled globally or per-tenant we will ACCEPT_WITH_ISSUES
            // (persist transactions but mark them WITH_ISSUES) instead of rejecting outright.
            $acceptWithIssues = false;
            $deferredAcceptedWithIssues = null;
            $mode = strtoupper(config('ingestion.default_mode', 'QUARANTINE'));
            if (!$checksumResults['valid']) {
                // Emit a clear log for grep-based incident correlation
                Log::warning('Checksum validation failed', [
                    'submission_uuid' => $request->submission_uuid,
                    'tenant_id' => $request->tenant_id,
                    'terminal_id' => $request->terminal_id,
                    'transaction_count' => $request->transaction_count,
                    // Provide sample of transaction_ids when present to aid debugging
                    'transaction_ids' => $request->transaction_count === 1
                        ? [$request->transaction['transaction_id'] ?? null]
                        : collect($request->transactions ?? [])->take(10)->pluck('transaction_id')->all(),
                    'errors' => $checksumResults['errors'],
                    'correlation_id' => $correlationId,
                ]);

                // Record structured REJECTED event (checksum) - create outside main transaction  
                Log::info('Creating SubmissionEvent for checksum failure', [
                    'submission_uuid' => $request->submission_uuid,
                    'correlation_id' => $correlationId
                ]);

                // Determine if we should accept with issues (global mode or tenant opt-in)
                try {
                    $tenant = null;
                    if ($request->tenant_id) {
                        $tenant = \App\Models\Tenant::find($request->tenant_id);
                    }
                    $tenantAccept = $tenant && ($tenant->accept_with_issues ?? false);
                } catch (\Throwable $_) {
                    $tenantAccept = false;
                }

                if ($mode === 'ACCEPT_WITH_ISSUES' || $tenantAccept) {
                    // Accept but mark as WITH_ISSUES: record for triage but continue processing.
                    $acceptWithIssues = true;

                    try {
                        $quarantine = \App\Models\IngestionQuarantine::create([
                            'submission_uuid' => $request->submission_uuid ?? null,
                            'tenant_id' => $request->tenant_id ?? null,
                            'terminal_id' => $request->terminal_id ?? null,
                            'payload' => $rawPayload,
                            'payload_checksum_received' => $request->payload_checksum ?? null,
                            'payload_checksum_computed' => $checksumResults['submission_checksum'] ?? null,
                            'status' => 'NEW',
                            'metadata' => [
                                'correlation_id' => $correlationId,
                                'errors' => $checksumResults['errors'],
                                'ip' => $request->ip(),
                            ],
                        ]);
                        Log::info('Ingestion payload quarantined (accepted with issues)', [
                            'quarantine_id' => $quarantine->id,
                            'submission_uuid' => $request->submission_uuid,
                            'correlation_id' => $correlationId,
                        ]);
                    } catch (\Throwable $qe) {
                        Log::warning('Failed to write ingestion_quarantine row', [
                            'submission_uuid' => $request->submission_uuid ?? 'unknown',
                            'error' => $qe->getMessage(),
                        ]);
                    }

                    // Defer emission of ACCEPTED_WITH_ISSUES until after processing & commit
                    $deferredAcceptedWithIssues = [
                        'submission_uuid' => $request->submission_uuid,
                        'tenant_id' => $request->tenant_id,
                        'terminal_id' => $request->terminal_id,
                        'status' => 'ACCEPTED_WITH_ISSUES',
                        'reason_code' => 'CHECKSUM_MISMATCH',
                        'reason_details' => ['errors' => $checksumResults['errors']],
                        'transaction_count' => (int) ($request->transaction_count ?? 0),
                        'occurred_at' => now(),
                        'correlation_id' => $correlationId,
                    ];

                    // Continue processing transactions below (do not return 422)
                } else {
                    // Quarantine-only / strict: commit and return REJECTED as before
                    DB::commit();

                    try {
                        $quarantine = \App\Models\IngestionQuarantine::create([
                            'submission_uuid' => $request->submission_uuid ?? null,
                            'tenant_id' => $request->tenant_id ?? null,
                            'terminal_id' => $request->terminal_id ?? null,
                            'payload' => $rawPayload,
                            'payload_checksum_received' => $request->payload_checksum ?? null,
                            'payload_checksum_computed' => $checksumResults['submission_checksum'] ?? null,
                            'status' => 'NEW',
                            'metadata' => [
                                'correlation_id' => $correlationId,
                                'errors' => $checksumResults['errors'],
                                'ip' => $request->ip(),
                            ],
                        ]);
                        Log::info('Ingestion payload quarantined', [
                            'quarantine_id' => $quarantine->id,
                            'submission_uuid' => $request->submission_uuid,
                            'correlation_id' => $correlationId,
                        ]);
                    } catch (\Throwable $qe) {
                        Log::warning('Failed to write ingestion_quarantine row', [
                            'submission_uuid' => $request->submission_uuid ?? 'unknown',
                            'error' => $qe->getMessage(),
                        ]);
                    }

                    try {
                        $submissionEvent = \App\Models\SubmissionEvent::create([
                            'submission_uuid' => $request->submission_uuid,
                            'tenant_id' => $request->tenant_id,
                            'terminal_id' => $request->terminal_id,
                            'status' => 'REJECTED',
                            'reason_code' => 'CHECKSUM_MISMATCH',
                            'reason_details' => ['errors' => $checksumResults['errors']],
                            'transaction_count' => (int) ($request->transaction_count ?? 0),
                            'occurred_at' => now(),
                            'correlation_id' => $correlationId,
                        ]);
                        Log::info('SubmissionEvent created successfully', [
                            'event_id' => $submissionEvent->id,
                            'submission_uuid' => $request->submission_uuid,
                            'reason_code' => 'CHECKSUM_MISMATCH'
                        ]);
                    } catch (\Throwable $te) {
                        Log::warning('Failed to write SubmissionEvent (REJECTED)', [
                            'submission_uuid' => $request->submission_uuid,
                            'error' => $te->getMessage(),
                            'trace' => $te->getTraceAsString()
                        ]);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid payload checksum',
                        'errors' => $checksumResults['errors']
                    ], 422);
                }
            }

            Log::info('storeOfficial: All validations passed, creating submission envelope');

            // Cache guard to reduce in-flight duplicates (best-effort; DB unique key remains source of truth)
            $cacheKey = sprintf('submission:lock:%s:%s', $request->terminal_id, $request->submission_uuid);
            $lockAcquired = Cache::add($cacheKey, 1, now()->addSeconds(60));
            if (!$lockAcquired) {
                Log::info('storeOfficial: Duplicate submission lock detected (treating as idempotent)', [
                    'submission_uuid' => $request->submission_uuid,
                    'terminal_id' => $request->terminal_id,
                ]);
                $existing = \App\Models\TransactionSubmission::where('terminal_id', $request->terminal_id)
                    ->where('submission_uuid', $request->submission_uuid)
                    ->first();
                $existingTransactions = \App\Models\Transaction::where('submission_uuid', $request->submission_uuid)->get();
                // Touch terminal liveness on idempotent duplicate via cache lock
                try {
                    if ($terminalFromToken instanceof \App\Models\PosTerminal) {
                        $terminalFromToken->last_seen_at = now();
                        $terminalFromToken->save();
                    }
                } catch (\Throwable $te) {
                    Log::warning('Failed to update terminal last_seen_at on cache-lock idempotent replay', [
                        'terminal_id' => $request->terminal_id,
                        'error' => $te->getMessage(),
                    ]);
                }
                try {
                    \DB::commit();
                } catch (\Throwable $t) {
                }
                return response()->json([
                    'success' => true,
                    'message' => 'Submission already processed (idempotent)',
                    'data' => [
                        'submission_uuid' => $request->submission_uuid,
                        'transaction_count' => $existing ? $existing->transaction_count : $existingTransactions->count(),
                        'status' => $existing ? $existing->status : 'COMPLETED',
                        'transactions' => $existingTransactions->pluck('transaction_id'),
                    ]
                ], 200);
            }

            // NOW create submission envelope (status RECEIVED) - after all validations pass
            try {
                $submission = \App\Models\TransactionSubmission::create([
                    'tenant_id' => $request->tenant_id,
                    'terminal_id' => $request->terminal_id,
                    'submission_uuid' => $request->submission_uuid,
                    'submission_timestamp' => $request->submission_timestamp,
                    'transaction_count' => $request->transaction_count,
                    'payload_checksum' => $request->payload_checksum,
                    'status' => \App\Models\TransactionSubmission::STATUS_RECEIVED,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle concurrent duplicate submission insertion gracefully (idempotent replay)
                $sqlState = $e->getCode();
                $isDuplicate = $sqlState == '23000' || str_contains(strtolower($e->getMessage()), 'duplicate entry');
                if ($isDuplicate) {
                    \Log::info('storeOfficial: Duplicate submission detected at insert (treating as idempotent)', [
                        'submission_uuid' => $request->submission_uuid,
                        'terminal_id' => $request->terminal_id,
                        'error' => $e->getMessage(),
                    ]);
                    $existing = \App\Models\TransactionSubmission::where('terminal_id', $request->terminal_id)
                        ->where('submission_uuid', $request->submission_uuid)
                        ->first();
                    $existingTransactions = \App\Models\Transaction::where('submission_uuid', $request->submission_uuid)->get();

                    // Touch terminal liveness on duplicate submission insert
                    try {
                        if ($terminalFromToken instanceof \App\Models\PosTerminal) {
                            $terminalFromToken->last_seen_at = now();
                            $terminalFromToken->save();
                        }
                    } catch (\Throwable $te) {
                        Log::warning('Failed to update terminal last_seen_at on duplicate submission insert', [
                            'terminal_id' => $request->terminal_id,
                            'error' => $te->getMessage(),
                        ]);
                    }

                    // Commit open transaction (if any) and return idempotent success
                    try {
                        \DB::commit();
                    } catch (\Throwable $t) {
                    }
                    return response()->json([
                        'success' => true,
                        'message' => 'Submission already processed (idempotent)',
                        'data' => [
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_count' => $existing ? $existing->transaction_count : $existingTransactions->count(),
                            'status' => $existing ? $existing->status : 'COMPLETED',
                            'transactions' => $existingTransactions->pluck('transaction_id'),
                        ]
                    ], 200);
                }
                throw $e; // non-duplicate DB error
            }

            Log::info('storeOfficial: Submission envelope created', [
                'submission_uuid' => $submission->submission_uuid,
                'terminal_id' => $submission->terminal_id,
            ]);

            // Record structured RECEIVED event
            try {
                \App\Models\SubmissionEvent::create([
                    'submission_uuid' => $submission->submission_uuid,
                    'tenant_id' => $request->tenant_id,
                    'terminal_id' => $request->terminal_id,
                    'status' => 'RECEIVED',
                    'reason_code' => null,
                    'reason_details' => null,
                    'transaction_count' => (int) ($request->transaction_count ?? 0),
                    'occurred_at' => now(),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ]);
            } catch (\Throwable $te) {
                Log::warning('Failed to write SubmissionEvent (RECEIVED)', [
                    'submission_uuid' => $submission->submission_uuid,
                    'error' => $te->getMessage(),
                ]);
            }

            Log::info('Checksum validation passed', [
                'submission_uuid' => $request->submission_uuid,
                'transaction_count' => $request->transaction_count,
            ]);

            // NOTE: Idempotency check now handled at the top of the method
            // Proceeding with transaction processing...

            // Get terminal and validate tenant
            $terminal = PosTerminal::with(['tenant.company'])->findOrFail($request->terminal_id);
            if ($terminal->tenant_id !== $request->tenant_id) {
                // Mark submission REJECTED if envelope exists
                try {
                    if (isset($submission) && $submission instanceof \App\Models\TransactionSubmission) {
                        $submission->status = \App\Models\TransactionSubmission::STATUS_REJECTED;
                        $submission->save();
                    }
                } catch (\Throwable $te) {
                    Log::warning('Failed to update submission status to REJECTED on tenant/terminal mismatch', [
                        'submission_uuid' => $request->submission_uuid,
                        'error' => $te->getMessage(),
                    ]);
                }

                // Emit submission-level REJECTED event
                $this->emitSubmissionEventSafe([
                    'submission_uuid' => $request->submission_uuid ?? 'unknown',
                    'tenant_id' => $request->tenant_id ?? null,
                    'terminal_id' => $request->terminal_id ?? null,
                    'status' => 'REJECTED',
                    'reason_code' => 'TENANT_TERMINAL_MISMATCH',
                    'reason_details' => [
                        'terminal_tenant_id' => $terminal->tenant_id,
                        'payload_tenant_id' => $request->tenant_id,
                    ],
                    'transaction_count' => (int) ($request->transaction_count ?? 0),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['tenant_id' => ['Terminal does not belong to the specified tenant']]
                ], 422);
            }

            // Process transactions
            $processedTransactions = [];
            $failedTransactions = [];
            $transactions = $request->transaction_count === 1 ? [$request->transaction] : $request->transactions;

            foreach ($transactions as $index => $transactionData) {
                Log::info('storeOfficial: Processing transaction', ['index' => $index, 'transaction_id' => $transactionData['transaction_id']]);
                try {
                    // Check for duplicate transaction
                    $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                        ->where('terminal_id', $terminal->id)
                        ->first();

                    if ($existingTransaction) {
                        Log::info('storeOfficial: Returning existing transaction for idempotency', [
                            'transaction_id' => $transactionData['transaction_id'],
                            'existing_id' => $existingTransaction->id
                        ]);
                        $processedTransactions[] = [
                            'transaction_id' => $existingTransaction->transaction_id,
                            'status' => 'success', // ✅ Fixed: Return success for idempotency
                            'message' => 'Transaction already processed'
                        ];
                        // Update terminal activity for idempotent transaction replay
                        try {
                            $terminal->last_seen_at = now();
                            if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                                $terminal->last_sale_at = now();
                            }
                            $terminal->save();
                        } catch (\Throwable $te) {
                            Log::warning('Failed to update terminal last_seen_at on idempotent transaction', [
                                'terminal_id' => $terminal->id,
                                'transaction_id' => $existingTransaction->transaction_id,
                                'error' => $te->getMessage(),
                            ]);
                        }
                        continue;
                    }
                    Log::info('storeOfficial: Creating transaction record', ['transaction_id' => $transactionData['transaction_id']]);

                    // Extract vatable_sales and vat_amount from taxes if present (sum if multiple entries)
                    $vatableSales = 0;
                    $vatAmount = 0;
                    $scVatExemptSales = 0;
                    if (isset($transactionData['taxes']) && is_array($transactionData['taxes'])) {
                        foreach ($transactionData['taxes'] as $tax) {
                            if (isset($tax['tax_type'])) {
                                $taxType = strtoupper($tax['tax_type']);
                                if ($taxType === 'VATABLE_SALES') {
                                    $vatableSales += $tax['amount'] ?? 0;
                                } elseif ($taxType === 'VAT' || $taxType === 'VAT_AMOUNT') {
                                    $vatAmount += $tax['amount'] ?? 0;
                                } elseif ($taxType === 'SC_VAT_EXEMPT_SALES' || $taxType === 'VAT-EXEMPT' || $taxType === 'EXEMPT' || $taxType === 'VATEXEMPT') {
                                    $scVatExemptSales += $tax['amount'] ?? 0;
                                }
                            }
                        }
                    }

                    // Aggregate adjustments
                    $promoDiscount = 0;
                    $seniorDiscount = 0;
                    $pwdDiscount = 0;
                    if (isset($transactionData['adjustments']) && is_array($transactionData['adjustments'])) {
                        foreach ($transactionData['adjustments'] as $adj) {
                            $type = strtolower($adj['adjustment_type'] ?? '');
                            $amt = $adj['amount'] ?? 0;
                            if ($type === 'promo_discount') {
                                $promoDiscount += $amt;
                            } elseif ($type === 'senior_discount') {
                                $seniorDiscount += $amt;
                            } elseif ($type === 'pwd_discount') {
                                $pwdDiscount += $amt;
                            }
                        }
                    }

                    // Normalize timestamp to UTC ISO-8601 for consistent DB storage
                    // Explicitly parse using application timezone and shift to it if necessary
                    // handle terminals sending local time with a 'Z' (signifying UTC) incorrectly
                    $dt = Carbon::parse($transactionData['transaction_timestamp'], config('app.timezone'));
                    if (str_ends_with(strtoupper($transactionData['transaction_timestamp']), 'Z')) {
                        $dt->shiftTimezone(config('app.timezone'));
                    }
                    $normalizedTimestampDb = $dt->utc()->format('Y-m-d\\TH:i:s.v\\Z');

                    $txPayload = [
                        'tenant_id' => $terminal->tenant_id,
                        'terminal_id' => $terminal->id,
                        'transaction_id' => $transactionData['transaction_id'],
                        'hardware_id' => $terminal->serial_number ?? 'UNKNOWN',
                        'transaction_timestamp' => $normalizedTimestampDb,
                        'gross_sales' => $transactionData['gross_sales'] ?? 0,
                        'net_sales' => $transactionData['net_sales'] ?? 0,
                        'vatable_sales' => $vatableSales,
                        'vat_amount' => $vatAmount,
                        'sc_vat_exempt_sales' => $scVatExemptSales,
                        'customer_code' => $transactionData['customer_code'] ?? ($terminal->tenant->company->customer_code ?? 'UNKNOWN'),
                        'promo_status' => $transactionData['promo_status'],
                        'payload_checksum' => $transactionData['payload_checksum'],
                        'receipt_no' => $transactionData['receipt_no'] ?? null,
                        'validation_status' => 'PENDING',
                        'submission_uuid' => $request->submission_uuid,
                        'submission_timestamp' => $request->submission_timestamp,
                    ];

                    if (Schema::hasColumn('transactions', 'promo_discount')) {
                        $txPayload['promo_discount'] = $promoDiscount;
                    }
                    if (Schema::hasColumn('transactions', 'senior_discount')) {
                        $txPayload['senior_discount'] = $seniorDiscount;
                    }
                    if (Schema::hasColumn('transactions', 'pwd_discount')) {
                        $txPayload['pwd_discount'] = $pwdDiscount;
                    }

                    $transaction = Transaction::create($txPayload);

                    // Process adjustments if present
                    if (isset($transactionData['adjustments']) && is_array($transactionData['adjustments'])) {
                        foreach ($transactionData['adjustments'] as $adjustment) {
                            // create via relation so transaction_pk is set correctly
                            $transaction->adjustments()->create([
                                'adjustment_type' => $adjustment['adjustment_type'],
                                'amount' => $adjustment['amount'],
                            ]);
                        }
                    }

                    // Process taxes if present
                    if (isset($transactionData['taxes']) && is_array($transactionData['taxes'])) {
                        foreach ($transactionData['taxes'] as $tax) {
                            // create via relation so transaction_pk is set correctly
                            $transaction->taxes()->create([
                                'tax_type' => $tax['tax_type'],
                                'amount' => $tax['amount'],
                            ]);
                        }
                    }

                    // Queue the transaction for processing
                    Log::info('storeOfficial: Dispatching ProcessTransactionJob', ['transaction_id' => $transaction->transaction_id]);
                    $shard = $terminal->tenant_id % 8;
                    ProcessTransactionJob::dispatch($transaction->id)
                        ->onQueue('transaction-processing:s' . $shard)
                        ->afterCommit();
                    Log::info('storeOfficial: ProcessTransactionJob dispatched', ['transaction_id' => $transaction->transaction_id]);

                    // Update terminal activity on successful transaction creation
                    try {
                        $terminal->last_seen_at = now();
                        if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                            $terminal->last_sale_at = now();
                        }
                        $terminal->save();
                    } catch (\Throwable $te) {
                        Log::warning('Failed to update terminal last_seen_at after official transaction creation', [
                            'terminal_id' => $terminal->id,
                            'transaction_id' => $transaction->transaction_id,
                            'error' => $te->getMessage(),
                        ]);
                    }

                    // (Notification suppressed here; final status notification sent by ProcessTransactionJob after validation)

                    // Add system log entry
                    \App\Models\SystemLog::create([
                        'type' => 'transaction',
                        'log_type' => 'OFFICIAL_TRANSACTION_INGESTION',
                        'severity' => 'info',
                        'terminal_uid' => $terminal->serial_number,
                        'transaction_id' => $transaction->transaction_id,
                        'message' => 'Official format transaction queued for processing',
                        'context' => json_encode([
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_id' => $transaction->transaction_id,
                            'gross_sales' => $transaction->gross_sales,
                            'net_sales' => $transaction->net_sales,
                            'terminal_id' => $terminal->id,
                            'adjustments_count' => count($transactionData['adjustments'] ?? []),
                            'taxes_count' => count($transactionData['taxes'] ?? []),
                        ])
                    ]);

                    // Add audit log entry
                    \App\Models\AuditLog::create([
                        'user_id' => optional(auth())->id(),
                        'ip_address' => request()->ip(),
                        'action' => 'OFFICIAL_TRANSACTION_RECEIVED',
                        'action_type' => 'OFFICIAL_TRANSACTION_RECEIVED',
                        'resource_type' => 'transaction',
                        'resource_id' => $transaction->transaction_id,
                        'auditable_type' => 'transaction',
                        'auditable_id' => $transaction->id,
                        'message' => 'Official format transaction received and queued for processing',
                        'metadata' => [
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_id' => $transaction->transaction_id,
                            'gross_sales' => $transaction->gross_sales,
                            'terminal_id' => $terminal->id,
                            'tenant_id' => $terminal->tenant_id,
                        ]
                    ]);

                    $processedTransactions[] = [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => 'queued',
                        'message' => 'Transaction queued for processing'
                    ];

                } catch (\Exception $e) {
                    Log::error('Failed to process official transaction', [
                        'submission_uuid' => $request->submission_uuid,
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Structured per-item failure log for observability (non-blocking)
                    try {
                        \App\Models\SystemLog::create([
                            'type' => 'transaction',
                            'log_type' => 'OFFICIAL_TRANSACTION_INGESTION_FAILED',
                            'severity' => 'error',
                            'terminal_uid' => $terminal->serial_number ?? null,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'message' => 'Official format transaction failed during ingestion',
                            'context' => [
                                'submission_uuid' => $request->submission_uuid,
                                'tenant_id' => $request->tenant_id,
                                'terminal_id' => $request->terminal_id,
                                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                'endpoint' => 'transactions.official.store',
                                'error_code' => 'PROCESSING_ERROR',
                                'error_message' => $e->getMessage(),
                                'payload_checksum' => $transactionData['payload_checksum'] ?? null,
                            ],
                        ]);
                    } catch (\Throwable $logEx) {
                        Log::warning('Failed to write SystemLog for OFFICIAL_TRANSACTION_INGESTION_FAILED', [
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'error' => $logEx->getMessage(),
                        ]);
                    }

                    $failedTransactions[] = [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];

                    // Record per-item failure event
                    try {
                        \App\Models\SubmissionEventItem::create([
                            'submission_uuid' => $request->submission_uuid,
                            'tenant_id' => $request->tenant_id,
                            'terminal_id' => $request->terminal_id,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'status' => 'FAILED',
                            'reason_code' => 'PROCESSING_ERROR',
                            'reason_details' => ['error' => $e->getMessage()],
                            'occurred_at' => now(),
                            'correlation_id' => $request->attributes->get('correlation_id'),
                        ]);

                        // Aggregate this failure into a centralized incident record
                        try {
                            app(\App\Services\IncidentFactory::class)->recordFailure([
                                'submission_uuid' => $request->submission_uuid,
                                'correlation_id' => $request->attributes->get('correlation_id'),
                                'tenant_id' => $request->tenant_id,
                                'terminal_id' => $request->terminal_id,
                                'reason_code' => 'PROCESSING_ERROR',
                                'source' => 'SUBMISSION_EVENT_ITEM',
                                'failed_count' => 1,
                                'reason_details' => ['error' => $e->getMessage()],
                            ]);
                        } catch (\Throwable $ie) {
                            Log::debug('IncidentFactory recordFailure failed for item', [
                                'submission_uuid' => $request->submission_uuid,
                                'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                                'error' => $ie->getMessage(),
                            ]);
                        }
                    } catch (\Throwable $te) {
                        Log::warning('Failed to write SubmissionEventItem (FAILED)', [
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                            'error' => $te->getMessage(),
                        ]);
                    }
                }
            }

            DB::commit();

            $totalProcessed = count($processedTransactions);
            $totalFailed = count($failedTransactions);

            Log::info('Official transaction processing completed', [
                'submission_uuid' => $request->submission_uuid,
                'processed_count' => $totalProcessed,
                'failed_count' => $totalFailed,
                'checksum_validation' => 'passed'
            ]);

            // Record structured COMPLETED event
            try {
                \App\Models\SubmissionEvent::create([
                    'submission_uuid' => $request->submission_uuid,
                    'tenant_id' => $request->tenant_id,
                    'terminal_id' => $request->terminal_id,
                    'status' => 'COMPLETED',
                    'reason_code' => $totalFailed > 0 ? 'PARTIAL_FAILURE' : null,
                    'reason_details' => $totalFailed > 0 ? ['failed_count' => $totalFailed] : null,
                    'transaction_count' => (int) ($request->transaction_count ?? 0),
                    'occurred_at' => now(),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ]);

                // If there were failures, also aggregate them into the incident view
                if ($totalFailed > 0) {
                    try {
                        app(\App\Services\IncidentFactory::class)->recordFailure([
                            'submission_uuid' => $request->submission_uuid,
                            'correlation_id' => $request->attributes->get('correlation_id'),
                            'tenant_id' => $request->tenant_id,
                            'terminal_id' => $request->terminal_id,
                            'reason_code' => 'PARTIAL_FAILURE',
                            'source' => 'SUBMISSION_EVENT',
                            'failed_count' => $totalFailed,
                            'reason_details' => ['failed_count' => $totalFailed],
                        ]);
                    } catch (\Throwable $ie) {
                        Log::debug('IncidentFactory recordFailure failed for submission', [
                            'submission_uuid' => $request->submission_uuid,
                            'error' => $ie->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $te) {
                Log::warning('Failed to write SubmissionEvent (COMPLETED)', [
                    'submission_uuid' => $request->submission_uuid,
                    'error' => $te->getMessage(),
                ]);
            }

            // Send batch notification to terminal if enabled and there's more than one transaction
            if (config('notifications.callbacks.enabled') && $request->transaction_count > 1 && $terminal->notifications_enabled && $terminal->callback_url) {
                $this->notifyTerminalOfBatchResult(
                    $request->submission_uuid,
                    $terminal,
                    $totalProcessed,
                    $totalFailed,
                    $processedTransactions,
                    $failedTransactions
                );
            }

            return response()->json([
                'success' => true,
                'message' => "Official submission processed: {$totalProcessed} successful, {$totalFailed} failed",
                'data' => [
                    'batch_id' => $request->submission_uuid,
                    'processed_count' => $totalProcessed,
                    'failed_count' => $totalFailed,
                    'checksum_validation' => 'passed',
                    'transactions' => array_merge($processedTransactions, $failedTransactions)
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();

            Log::warning('Official transaction validation failed', [
                'submission_uuid' => $request->submission_uuid ?? 'unknown',
                'errors' => $e->errors()
            ]);

            // Record structured REJECTED event (generic validation failure)
            $this->emitSubmissionEventSafe([
                'submission_uuid' => $request->submission_uuid ?? 'unknown',
                'tenant_id' => $request->tenant_id ?? null,
                'terminal_id' => $request->terminal_id ?? null,
                'status' => 'REJECTED',
                'reason_code' => 'VALIDATION_FAILED',
                'reason_details' => ['errors' => $e->errors()],
                'transaction_count' => (int) ($request->transaction_count ?? 0),
                'correlation_id' => $request->attributes->get('correlation_id'),
            ]);

            // (Validation failure notification suppressed; errors surfaced in response and async notifications handled elsewhere)

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Official transaction API error', [
                'submission_uuid' => $request->submission_uuid ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['password', 'token'])
            ]);

            // Try to log the error to system logs
            try {
                $terminalId = $request->terminal_id ?? 'unknown';
                $terminal = is_numeric($terminalId) ? PosTerminal::find($terminalId) : null;

                \App\Models\SystemLog::create([
                    'type' => 'error',
                    'log_type' => 'OFFICIAL_TRANSACTION_ERROR',
                    'severity' => 'error',
                    'terminal_uid' => $terminal ? $terminal->serial_number : 'unknown',
                    'transaction_id' => null,
                    'message' => 'Official transaction submission failed: ' . $e->getMessage(),
                    'context' => json_encode([
                        'submission_uuid' => $request->submission_uuid ?? 'unknown',
                        'error' => $e->getMessage(),
                        'payload' => $request->all(),
                        'trace' => $e->getTraceAsString()
                    ])
                ]);
            } catch (\Exception $logError) {
                Log::error('Failed to create system log', [
                    'error' => $logError->getMessage(),
                    'original_error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process official transaction submission: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

    /**
     * Process a batch of transactions from the official TSMS payload format
     */
    public function processOfficialSubmission(Request $request)
    {
        // Decode raw JSON input before Laravel mutates it
        $rawJson = $request->getContent();
        $submission = json_decode($rawJson, true);

        // Basic validation of submission-level fields
        validator($submission, [
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64',
        ])->validate();

        // Validate tenant and terminal consistency
        $terminal = PosTerminal::findOrFail($submission['terminal_id']);
        if ((int) $terminal->tenant_id !== (int) $submission['tenant_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal does not belong to the specified tenant.',
            ], 422);
        }

        // Determine if it's single or batch submission
        $isSingle = $submission['transaction_count'] === 1;

        if ($isSingle) {
            // Validate single transaction structure
            validator($submission['transaction'], [
                'transaction_id' => 'required|string',
                'transaction_timestamp' => 'required|date',
                'gross_sales' => 'required|numeric',
                'payload_checksum' => 'required|string|min:64|max:64',
            ])->validate();
        } else {
            // Validate batch transaction structure
            validator($submission['transactions'], [
                '*.transaction_id' => 'required|string',
                '*.transaction_timestamp' => 'required|date',
                '*.gross_sales' => 'required|numeric',
                '*.payload_checksum' => 'required|string|min:64|max:64',
            ])->validate();
        }

        // Count validation
        $actualCount = $isSingle ? 1 : count($submission['transactions']);
        if ($actualCount !== $submission['transaction_count']) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction count mismatch.',
            ], 422);
        }

        // Checksum validation using raw payload
        $checksumService = new PayloadChecksumService();
        $checksumResults = $checksumService->validateSubmissionChecksumsFromRaw($rawJson);

        if (!$checksumResults['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload checksum.',
            ], 422);
        }

        try {
            // Find terminal
            $terminal = PosTerminal::with('tenant.company')->findOrFail($submission['terminal_id']);

            Log::info('storeOfficial: Terminal loaded', ['terminal_id' => $terminal->id, 'tenant_id' => $terminal->tenant_id]);

            // Ensure terminal belongs to the specified tenant to prevent cross-mapping
            if ((int) $terminal->tenant_id !== (int) $submission['tenant_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['tenant_id' => ['Terminal does not belong to the specified tenant']]
                ], 422);
            }

            // Normalize transaction list
            $transactions = $isSingle ? [$submission['transaction']] : $submission['transactions'];

            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;

            foreach ($transactions as $transaction) {
                // Optional per-item guard: if transaction payload includes tenant_id, it must match terminal's tenant
                if (isset($transaction['tenant_id']) && (int) $transaction['tenant_id'] !== (int) $terminal->tenant_id) {
                    Log::warning('processOfficialSubmission: Tenant ID mismatch in transaction item', [
                        'payload_tenant_id' => $transaction['tenant_id'],
                        'terminal_tenant_id' => $terminal->tenant_id,
                        'terminal_id' => $terminal->id,
                        'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                        'submission_uuid' => $submission['submission_uuid'] ?? 'missing',
                    ]);
                    // Structured log for per-item tenant mismatch (official submission)
                    try {
                        \App\Models\SystemLog::create([
                            'type' => 'transaction',
                            'log_type' => 'TRANSACTION_TENANT_MISMATCH',
                            'severity' => 'error',
                            'terminal_uid' => $terminal->serial_number ?? null,
                            'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                            'message' => 'Official submission transaction tenant_id does not match terminal tenant',
                            'context' => [
                                'submission_uuid' => $submission['submission_uuid'] ?? 'missing',
                                'transaction_tenant_id' => $transaction['tenant_id'],
                                'terminal_tenant_id' => $terminal->tenant_id,
                                'terminal_id' => $terminal->id,
                                'endpoint' => 'transactions.official.process',
                            ],
                        ]);
                    } catch (\Throwable $logEx) {
                        Log::warning('Failed to write SystemLog for TRANSACTION_TENANT_MISMATCH (official)', [
                            'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                            'error' => $logEx->getMessage(),
                        ]);
                    }
                    $failedTransactions[] = [
                        'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                        'status' => 'failed',
                        'message' => 'Tenant ID mismatch: transaction tenant does not match terminal tenant'
                    ];
                    $failedCount++;
                    continue; // skip this item but continue the batch
                }

                $result = $this->processTransaction($transaction, $terminal);

                if ($result['status'] === 'success') {
                    $processedTransactions[] = $result;
                    $processedCount++;
                } else {
                    $failedTransactions[] = $result;
                    $failedCount++;
                }
            }

            // Dispatch failure monitoring job
            if ($failedCount > 0) {
                CheckTransactionFailureThresholdsJob::dispatch($terminal->id);
            }

            // Notify terminal if applicable
            if (config('notifications.callbacks.enabled') && $terminal->notifications_enabled && $terminal->callback_url) {
                $this->notifyTerminalOfBatchResult(
                    $submission['batch_id'] ?? $submission['submission_uuid'],
                    $terminal,
                    $processedCount,
                    $failedCount,
                    $processedTransactions,
                    $failedTransactions
                );
            }

            // Notify admin on failure
            if ($failedCount > 0) {
                $this->notificationService->notifyBatchProcessingFailure(
                    $submission['batch_id'] ?? $submission['submission_uuid'],
                    $submission['transaction_count'],
                    $failedTransactions
                );
            }

            return response()->json([
                'success' => true,
                'message' => "Batch processed: {$processedCount} successful, {$failedCount} failed",
                'data' => [
                    'batch_id' => $submission['batch_id'] ?? $submission['submission_uuid'],
                    'processed_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'transactions' => array_merge($processedTransactions, $failedTransactions)
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing official batch submission', [
                'terminal_id' => $submission['terminal_id'] ?? 'unknown',
                'submission_uuid' => $submission['submission_uuid'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing batch submission',
                'error' => 'An unexpected error occurred while processing the batch'
            ], 500);
        }
    }


    /**
     * Validate the checksum of a transaction payload.
     *
     * @param array $transaction
     * @return bool
     */
    private function validateTransactionChecksum(array $transaction): bool
    {
        // Use SHA-256 for official payloads, fallback to md5 for legacy
        if (!isset($transaction['payload_checksum'])) {
            return false;
        }

        // Remove the checksum field before calculating
        $payload = $transaction;
        unset($payload['payload_checksum']);

        // Calculate checksum using correct flags
        $calculatedChecksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Compare with provided checksum (case-insensitive)
        return strtolower($calculatedChecksum) === strtolower($transaction['payload_checksum']);
    }

    /**
     * Process a single transaction from the official TSMS payload
     */
    private function processTransaction(array $transaction, PosTerminal $terminal)
    {
        $validationStatus = 'VALID';
        $validationErrors = [];
        $isSaved = false;

        try {
            // Basic validation
            if (!$this->validateRequiredFields($transaction)) {
                $validationStatus = 'INVALID';
                $validationErrors['missing_fields'] = 'Required transaction fields missing';
                return [
                    'transaction_id' => $transaction['transaction_id'],
                    'status' => 'failed',
                    'errors' => $validationErrors
                ];
            }

            // Checksum validation
            if (!$this->validateTransactionChecksum($transaction)) {
                $validationStatus = 'INVALID';
                $validationErrors['checksum'] = 'Transaction checksum validation failed';
                return [
                    'transaction_id' => $transaction['transaction_id'],
                    'status' => 'failed',
                    'errors' => $validationErrors
                ];
            }

            // Check for existing transaction
            $existingTransaction = Transaction::where('transaction_id', $transaction['transaction_id'])
                ->where('terminal_id', $terminal->id)
                ->first();

            if ($existingTransaction) {
                // If transaction already exists, return success for idempotency
                return [
                    'transaction_id' => $transaction['transaction_id'],
                    'status' => 'success',
                    'message' => 'Transaction already processed',
                ];

            }

            // Create transaction - aggregate taxes and adjustments into stored columns
            $vatableSales = 0;
            $vatAmount = 0;
            $scVatExemptSales = 0;
            if (isset($transaction['taxes']) && is_array($transaction['taxes'])) {
                foreach ($transaction['taxes'] as $tax) {
                    $taxType = strtoupper($tax['tax_type'] ?? '');
                    if ($taxType === 'VATABLE_SALES') {
                        $vatableSales += $tax['amount'] ?? 0;
                    } elseif ($taxType === 'VAT' || $taxType === 'VAT_AMOUNT') {
                        $vatAmount += $tax['amount'] ?? 0;
                    } elseif ($taxType === 'SC_VAT_EXEMPT_SALES' || $taxType === 'VAT-EXEMPT' || $taxType === 'EXEMPT' || $taxType === 'VATEXEMPT') {
                        $scVatExemptSales += $tax['amount'] ?? 0;
                    }
                }
            }

            $promoDiscount = 0;
            $seniorDiscount = 0;
            $pwdDiscount = 0;
            if (isset($transaction['adjustments']) && is_array($transaction['adjustments'])) {
                foreach ($transaction['adjustments'] as $adj) {
                    $type = strtolower($adj['adjustment_type'] ?? '');
                    $amt = $adj['amount'] ?? 0;
                    if ($type === 'promo_discount') {
                        $promoDiscount += $amt;
                    } elseif ($type === 'senior_discount') {
                        $seniorDiscount += $amt;
                    } elseif ($type === 'pwd_discount') {
                        $pwdDiscount += $amt;
                    }
                }
            }

            // Normalize timestamp to UTC ISO-8601 for consistent DB storage
            // Explicitly parse using application timezone and shift to it if necessary
            // handle terminals sending local time with a 'Z' (signifying UTC) incorrectly
            $dt = Carbon::parse($transaction['transaction_timestamp'], config('app.timezone'));
            if (str_ends_with(strtoupper($transaction['transaction_timestamp']), 'Z')) {
                $dt->shiftTimezone(config('app.timezone'));
            }
            $normalizedTimestampDb = $dt->utc()->format('Y-m-d\\TH:i:s.v\\Z');

            $txPayload = [
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $transaction['transaction_id'],
                'transaction_timestamp' => $normalizedTimestampDb,
                'gross_sales' => $transaction['gross_sales'] ?? 0,
                'net_sales' => $transaction['net_sales'] ?? 0,
                'customer_code' => $transaction['customer_code'] ?? ($terminal->tenant->company->customer_code ?? 'UNKNOWN'),
                'promo_status' => $transaction['promo_status'],
                'receipt_no' => $transaction['receipt_no'] ?? null,
                'payload_checksum' => $transaction['payload_checksum'] ?? '',
                'validation_status' => $validationStatus,
                'submission_uuid' => $transaction['submission_uuid'] ?? null,
                'vatable_sales' => $vatableSales,
                'vat_amount' => $vatAmount,
                'sc_vat_exempt_sales' => $scVatExemptSales,
            ];

            if (Schema::hasColumn('transactions', 'promo_discount')) {
                $txPayload['promo_discount'] = $promoDiscount;
            }
            if (Schema::hasColumn('transactions', 'senior_discount')) {
                $txPayload['senior_discount'] = $seniorDiscount;
            }
            if (Schema::hasColumn('transactions', 'pwd_discount')) {
                $txPayload['pwd_discount'] = $pwdDiscount;
            }

            $transactionModel = Transaction::create($txPayload);
            $isSaved = true;

            // Process adjustments & taxes
            $this->processAdjustmentsAndTaxes($transactionModel, $transaction);

            // Check if terminal has notifications enabled and has a callback URL
            if (config('notifications.callbacks.enabled') && $terminal->notifications_enabled && $terminal->callback_url) {
                $this->notifyTerminalOfValidationResult(
                    [
                        'transaction_id' => $transactionModel->transaction_id,
                        'terminal_id' => $terminal->id,
                        'submission_uuid' => $transaction['submission_uuid'] ?? null,
                        'customer_code' => $transactionModel->customer_code,
                    ],
                    $validationStatus,
                    $validationErrors,
                    $terminal->callback_url
                );
            }

            return [
                'transaction_id' => $transaction['transaction_id'],
                'status' => 'success',
            ];

        } catch (\Exception $e) {
            // Set validation status to INVALID and record error
            $validationStatus = 'INVALID';
            $validationErrors['system'] = $e->getMessage();

            // If we already created the transaction, update its validation status
            if ($isSaved && isset($transactionModel)) {
                $transactionModel->update(['validation_status' => $validationStatus]);
            }

            // Try to notify terminal of error if enabled
            if (config('notifications.callbacks.enabled') && $terminal->notifications_enabled && $terminal->callback_url) {
                $this->notifyTerminalOfValidationResult(
                    [
                        'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                        'terminal_id' => $terminal->id,
                        'submission_uuid' => $transaction['submission_uuid'] ?? null,
                    ],
                    'INVALID',
                    ['system_error' => 'Transaction processing failed: ' . $e->getMessage()],
                    $terminal->callback_url
                );
            }

            // Log the error
            Log::error('Transaction processing error', [
                'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                'terminal_id' => $terminal->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                'status' => 'failed',
                'errors' => ['system' => 'System error occurred while processing transaction']
            ];
        }
    }

    /**
     * Validate detailed transaction structure (moved from TSMSTransactionRequest)
     * Creates audit trail for validation failures
     */
    private function validateDetailedStructure(\Illuminate\Http\Request $request, string $correlationId): void
    {
        $isSingle = $request->transaction_count === 1;

        // Build detailed validation rules
        $rules = [];
        if ($isSingle) {
            $rules = [
                'transaction' => 'required|array',
                'transaction.transaction_id' => 'required|string|uuid',
                'transaction.transaction_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
                'transaction.gross_sales' => 'required|numeric',
                'transaction.net_sales' => 'required|numeric',
                'transaction.promo_status' => 'required|string',
                'transaction.receipt_no' => 'nullable|string|max:128',
                'transaction.customer_code' => 'required|string',
                'transaction.payload_checksum' => 'required|string|min:64|max:64',
                'transaction.adjustments' => 'required|array|min:7',
                'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                'transaction.adjustments.*.amount' => 'required|numeric',
                'transaction.taxes' => 'required|array|min:4',
                'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                'transaction.taxes.*.amount' => 'required|numeric',
            ];
        } else {
            $rules = [
                'transactions' => 'required|array|min:1',
                'transactions.*.transaction_id' => 'required|string|uuid',
                'transactions.*.transaction_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
                'transactions.*.gross_sales' => 'required|numeric',
                'transactions.*.net_sales' => 'required|numeric',
                'transactions.*.promo_status' => 'required|string',
                'transactions.*.receipt_no' => 'nullable|string|max:128',
                'transactions.*.customer_code' => 'required|string',
                'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                'transactions.*.adjustments' => 'required|array|min:7',
                'transactions.*.adjustments.*.adjustment_type' => 'required_with:transactions.*.adjustments|string',
                'transactions.*.adjustments.*.amount' => 'required|numeric',
                'transactions.*.taxes' => 'required|array|min:4',
                'transactions.*.taxes.*.tax_type' => 'required_with:transactions.*.taxes|string',
                'transactions.*.taxes.*.amount' => 'required|numeric',
            ];
        }

        // Validate structure
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            // Create audit event for structure validation failure
            $this->createRejectionAuditEvent(
                $request,
                'STRUCTURE_INVALID',
                $validator->errors()->toArray(),
                $correlationId
            );

            // Throw validation exception
            throw new \Illuminate\Validation\ValidationException($validator);
        }
    }

    /**
     * Create rejection audit event for validation failures
     */
    private function createRejectionAuditEvent(\Illuminate\Http\Request $request, string $reasonCode, array $errors, string $correlationId = null): void
    {
        try {
            \App\Models\SubmissionEvent::create([
                'submission_uuid' => $request->submission_uuid,
                'tenant_id' => $request->tenant_id,
                'terminal_id' => $request->terminal_id,
                'status' => 'REJECTED',
                'reason_code' => $reasonCode,
                'reason_details' => ['errors' => $errors],
                'transaction_count' => (int) ($request->transaction_count ?? 0),
                'occurred_at' => now(),
                'correlation_id' => $correlationId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create SubmissionEvent', [
                'submission_uuid' => $request->submission_uuid,
                'reason_code' => $reasonCode,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId
            ]);
        }
    }
}