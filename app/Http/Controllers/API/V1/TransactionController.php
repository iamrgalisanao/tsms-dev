<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\NotificationService;
use App\Http\Requests\TSMSTransactionRequest;
use Carbon\Carbon;
// Removed duplicate Cache import

class TransactionController extends Controller
{
    /**
     * Void a transaction from POS
     */
    public function voidFromPOS(Request $request, $transaction_id)
    {
        try {
            DB::beginTransaction();

            // Validate request includes transaction_id and matches route parameter
            $request->validate([
                // Require RFC 4122 UUID (Laravel uuid rule validates format) to prevent accepting malformed IDs
                'transaction_id' => 'required|string|uuid|max:191',
                'void_reason' => 'required|string|max:255',
                'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 required for POS requests
            ]);

            // Ensure request transaction_id matches route parameter for security
            if ($request->transaction_id !== $transaction_id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction ID mismatch',
                    'errors' => ['transaction_id' => ['Request transaction_id must match the transaction being voided']]
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

            $transaction = Transaction::where('transaction_id', $transaction_id)
                ->where('terminal_id', $posTerminal->id)
                ->first();
            
            if (!$transaction) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found or does not belong to this terminal'
                ], 404);
            }

            // Fix: Move variable assignment after null check
            $tenant_id = $transaction->tenant_id ?? null;
            $terminal_id = $posTerminal->id;

            if ($transaction->voided_at) {
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

            // Use PayloadChecksumService for consistent checksum validation
            $checksumService = new \App\Services\PayloadChecksumService();
            $expectedPayload = [
                'transaction_id' => $request->transaction_id,
                'void_reason' => $request->void_reason,
            ];
            
            $expectedChecksum = $checksumService->computeChecksum($expectedPayload);
            
            if ($request->payload_checksum !== $expectedChecksum) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payload checksum',
                    'errors' => ['payload_checksum' => ['Checksum validation failed']]
                ], 422);
            }

            // Update transaction with void information and timestamp
            $voidedAt = now();
            $transaction->voided_at = $voidedAt;
            $transaction->void_reason = $request->void_reason;
            $transaction->save();

            Log::info('Transaction voided successfully', [
                'transaction_id' => $transaction->transaction_id,
                'voided_at' => $voidedAt,
                'void_reason' => $request->void_reason,
                'initiated_by' => 'POS',
                'terminal_id' => $posTerminal->id
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
                        'request_transaction_id' => $request->transaction_id
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
                    'user_id' => auth()->id(),
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
                        'tenant_id' => $tenant_id,
                        'initiated_by' => 'POS',
                        'voided_at' => $voidedAt,
                        'request_transaction_id' => $request->transaction_id
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
                    'initiated_by' => 'POS',
                    'terminal_serial' => $posTerminal->serial_number,
                ];
                if (method_exists($forwardingService, 'forwardVoidedTransaction')) {
                    $forwardingService->forwardVoidedTransaction($payload);
                } else {
                    // Fallback: send via generic forward method
                    $forwardingService->forward($payload);
                }
            } catch (\Exception $e) {
                // Don't rollback for forwarding failures - void operation should still succeed
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
        Log::info('storeOfficial: Request received', [
            'submission_uuid' => $request->submission_uuid,
            'terminal_id' => $request->terminal_id,
            'tenant_id' => $request->tenant_id,
        ]);

        // Convert request to array for checksum validation
        $submission = $request->all();
        $rawJson = json_encode($submission);
        $checksumService = app(\App\Services\PayloadChecksumService::class);
        $checksumResult = $checksumService->validateSubmissionChecksumsFromRaw($rawJson);
        if (!$checksumResult['valid']) {
            Log::warning('storeOfficial: Checksum validation failed', [
                'submission_uuid' => $submission['submission_uuid'] ?? null,
                'errors' => $checksumResult['errors'],
            ]);

            $this->createRejectionAuditEvent(
                $submission,
                'CHECKSUM_MISMATCH',
                ['payload_checksum' => $checksumResult['errors']],
                $submission['submission_uuid'] ?? null
            );
            throw new \Illuminate\Validation\ValidationException(
                Validator::make([], []),
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'payload_checksum' => $checksumResult['errors'],
                    ],
                ], 422)
            );
        }

        $transactions = [];
        
        // Block batch submissions (transactions array) for Phase 1
        if ($request->has('transactions')) {
            $this->createRejectionAuditEvent(
                $submission,
                'BATCH_DISABLED',
                ['transactions' => 'Batch transaction submission is temporarily disabled. Please use single transaction submission mode.'],
                $submission['submission_uuid'] ?? null
            );
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => [
                    'transactions' => 'Batch transaction submission is temporarily disabled. Please use single transaction submission mode.',
                ],
            ], 422);
        }

        // Only allow single transaction submission
        if ($request->has('transaction')) {
            $transactions = [$request->input('transaction')];
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => [
                    'transaction' => 'Single transaction object is required.',
                ],
            ], 422);
        }
        $processed = [];
        $failed = [];
        $service = $this->getTransactionIngestService();
        foreach ($transactions as $tx) {
            try {
                // Compose payload for ingest (merge submission-level fields)
                $payload = array_merge($tx, [
                    'submission_uuid' => $request->submission_uuid,
                    'submission_timestamp' => $request->submission_timestamp,
                    'tenant_id' => $request->tenant_id,
                    'terminal_id' => $request->terminal_id,
                ]);
                $result = $service->ingest($payload);
                
                // RESTORED: Real-time job dispatch for seconds-level latency
                if ($result['status'] === 'accepted' || $result['status'] === 'already_processed') {
                    if (isset($result['transaction_id'])) {
                        $transaction = \App\Models\Transaction::where('transaction_id', $result['transaction_id'])->first();
                        if ($transaction) {
                            $shard = (int) ($request->tenant_id % 8);
                            \App\Jobs\ProcessTransactionJob::dispatch($transaction->id)
                                ->onQueue('transaction-processing:s' . $shard)
                                ->afterCommit();
                                
                            Log::info('storeOfficial: Real-time dispatch successful', [
                                'transaction_id' => $transaction->transaction_id,
                                'queue' => 'transaction-processing:s' . $shard
                            ]);
                        }
                    }
                }

                $processed[] = [
                    'transaction_id' => $result['transaction_id'],
                    'status' => $result['status'] === 'accepted' || $result['status'] === 'already_processed' ? 'success' : 'failed',
                    'message' => $result['message'] ?? 'Transaction processed'
                ];
            } catch (\Exception $e) {
                $failed[] = [
                    'transaction_id' => $tx['transaction_id'] ?? null,
                    'status' => 'failed',
                    'message' => $e->getMessage()
                ];
            }
        }
        return response()->json([
            'success' => true,
            'message' => 'Submission processed',
            'data' => [
                'submission_uuid' => $request->submission_uuid,
                'processed_count' => count($processed),
                'failed_count' => count($failed),
                'transactions' => array_merge($processed, $failed)
            ]
        ], 200);
    }
    /**
     * @var \App\Services\TransactionIngestService|null
     */
    private $transactionIngestService = null;

    public function setTransactionIngestService($service)
    {
        $this->transactionIngestService = $service;
    }

    public function getTransactionIngestService()
    {
        if (!$this->transactionIngestService) {
            $this->transactionIngestService = app(\App\Services\TransactionIngestService::class);
        }
        return $this->transactionIngestService;
    }
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
        $this->notificationService = $notificationService;
    }

    /**
     * Validate that all required fields are present in the transaction array.
     *
     * @param array $transaction
     * @return bool
     */
    // Removed unused validateRequiredFields()



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
        // Batch transaction submission is temporarily disabled per client agreement for Phase 1
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => [
                'batch' => 'Batch transaction submission is temporarily disabled. Please use single transaction submission mode.',
            ],
        ], 422);

        try {
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
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['tenant_id' => ['Terminal does not belong to the specified tenant']]
                ], 422);
            }
            // Customer code tenant-binding policy: only per-item checks below

            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;
            $service = $this->getTransactionIngestService();

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

                    // Compose payload for ingest (merge batch-level fields)
                    $payload = array_merge($transactionData, [
                        'tenant_id' => $request->tenant_id,
                        'terminal_id' => $request->terminal_id,
                    ]);
                    $result = $service->ingest($payload);
                    if ($result['status'] === 'accepted' || $result['status'] === 'already_processed') {
                        // RESTORED: Real-time job dispatch for seconds-level latency
                        if (isset($result['transaction_id'])) {
                            $transaction = \App\Models\Transaction::where('transaction_id', $result['transaction_id'])->first();
                            if ($transaction) {
                                $shard = (int) ($request->tenant_id % 8);
                                \App\Jobs\ProcessTransactionJob::dispatch($transaction->id)
                                    ->onQueue('transaction-processing:s' . $shard)
                                    ->afterCommit();
                            }
                        }

                        $processedTransactions[] = [
                            'transaction_id' => $result['transaction_id'],
                            'status' => 'success',
                            'message' => $result['message'] ?? 'Transaction processed'
                        ];
                        $processedCount++;
                    } else {
                        $failedTransactions[] = [
                            'transaction_id' => $result['transaction_id'] ?? null,
                            'status' => 'failed',
                            'message' => $result['message'] ?? 'Transaction failed'
                        ];
                        $failedCount++;
                    }
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
                    'transactions' => array_merge($processedTransactions, $failedTransactions),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Batch transaction processing failed', [
                'batch_id' => $request->batch_id ?? null,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Batch processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process a batch of transactions from the official TSMS payload format
     */
    public function processOfficialSubmission(Request $request)
    {
        // 1) Basic submission-level validation
        $rawJson = $request->getContent();
        $submission = json_decode($rawJson, true);
        validator($submission, [
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64',
        ])->validate();

        // 2) Checksum validation (before any further processing)
        $checksumService = app(\App\Services\PayloadChecksumService::class);
        $checksumResult = $checksumService->validateSubmissionChecksumsFromRaw($rawJson);
        if (!$checksumResult['valid']) {
            $this->createRejectionAuditEvent(
                $submission,
                'CHECKSUM_MISMATCH',
                ['payload_checksum' => $checksumResult['errors']],
                $submission['submission_uuid'] ?? null
            );
            // Throw ValidationException to ensure Laravel returns 422
            throw new \Illuminate\Validation\ValidationException(
                Validator::make([], []),
                response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'payload_checksum' => $checksumResult['errors'],
                    ],
                ], 422)
            );
        }

        // 3) Tenant/terminal consistency check
        $terminal = PosTerminal::findOrFail($submission['terminal_id']);
        if ((int) $terminal->tenant_id !== (int) $submission['tenant_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal does not belong to the specified tenant.',
            ], 422);
        }

        // 4) Single/batch structure validation and ingest
        $isSingle = $submission['transaction_count'] === 1;
        $transactionRules = [
            'transaction_id' => 'required|string',
            'transaction_timestamp' => 'required|date',
            'gross_sales' => 'required|numeric',
            'net_sales' => 'required|numeric',
            'promo_status' => 'required|string',
            'customer_code' => 'required|string',
            'receipt_no' => 'nullable|string|max:128',
            'payload_checksum' => 'required|string|min:64|max:64',
            'adjustments' => 'required|array|min:1',
            'adjustments.*.adjustment_type' => 'required_with:adjustments|string',
            'adjustments.*.amount' => 'required|numeric',
            'taxes' => 'required|array|min:1',
            'taxes.*.tax_type' => 'required_with:taxes|string',
            'taxes.*.amount' => 'required|numeric',
        ];
        if ($isSingle) {
            // Validate single transaction structure
            validator($submission['transaction'], $transactionRules)->validate();
            $transactions = [$submission['transaction']];
        } else {
            // Validate each transaction in the batch using wildcard rules
            $batchRules = [];
            foreach ($transactionRules as $key => $rule) {
                $batchRules["*.{$key}"] = $rule;
            }
            $batchValidator = validator($submission['transactions'], $batchRules);
            if ($batchValidator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $batchValidator->errors()
                ], 422);
            }
            $transactions = $submission['transactions'];
        }

        $processedTransactions = [];
        $failedTransactions = [];
        $processedCount = 0;
        $failedCount = 0;
        $service = $this->getTransactionIngestService();
        foreach ($transactions as $transaction) {
            if (isset($transaction['tenant_id']) && (int) $transaction['tenant_id'] !== (int) $terminal->tenant_id) {
                Log::warning('processOfficialSubmission: Tenant ID mismatch in transaction item', [
                    'payload_tenant_id' => $transaction['tenant_id'],
                    'terminal_tenant_id' => $terminal->tenant_id,
                    'terminal_id' => $terminal->id,
                    'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                    'submission_uuid' => $submission['submission_uuid'] ?? 'missing',
                ]);
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
                continue;
            }
            $payload = array_merge($transaction, [
                'tenant_id' => $submission['tenant_id'],
                'terminal_id' => $submission['terminal_id'],
                'submission_uuid' => $submission['submission_uuid'] ?? null,
                'batch_id' => $submission['batch_id'] ?? null,
            ]);
            try {
                $result = $service->ingest($payload);
                if ($result['status'] === 'accepted' || $result['status'] === 'already_processed') {
                    $processedTransactions[] = $result;
                    $processedCount++;
                } else {
                    $failedTransactions[] = $result;
                    $failedCount++;
                }
            } catch (\Exception $e) {
                Log::error('Failed to process transaction in official submission', [
                    'submission_uuid' => $submission['submission_uuid'] ?? null,
                    'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                $failedTransactions[] = [
                    'transaction_id' => $transaction['transaction_id'] ?? 'unknown',
                    'status' => 'failed',
                    'message' => $e->getMessage()
                ];
                $failedCount++;
            }
        }
        return response()->json([
            'success' => true,
            'processed_count' => $processedCount,
            'failed_count' => $failedCount,
            'processed_transactions' => $processedTransactions,
            'failed_transactions' => $failedTransactions,
        ]);
    }

    /**
     * Create rejection audit event for validation failures
     */
    private function createRejectionAuditEvent(array $submission, string $reasonCode, array $errors, ?string $correlationId = null): void
    {
        try {
            \App\Models\SubmissionEvent::create([
                'submission_uuid' => $submission['submission_uuid'] ?? null,
                'tenant_id' => $submission['tenant_id'] ?? null,
                'terminal_id' => $submission['terminal_id'] ?? null,
                'status' => 'REJECTED',
                'reason_code' => $reasonCode,
                'reason_details' => ['errors' => $errors],
                'transaction_count' => (int) ($submission['transaction_count'] ?? 0),
                'occurred_at' => now(),
                'correlation_id' => $correlationId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create SubmissionEvent', [
                'submission_uuid' => $submission['submission_uuid'] ?? null,
                'reason_code' => $reasonCode,
                'error' => $e->getMessage(),
                'correlation_id' => $correlationId
            ]);
        }
    }

    /**
     * Get the status of a transaction by its transaction_id
     */
    public function status($id)
    {
        // Enforce terminal ownership
        $posTerminal = request()->user();
        if (!$posTerminal) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $transaction = Transaction::where('transaction_id', $id)
            ->where('terminal_id', $posTerminal->id)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or does not belong to this terminal'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->transaction_id,
                'validation_status' => $transaction->validation_status,
                'job_status' => $transaction->job_status,
                'is_voided' => $transaction->isVoided(),
                'refund_status' => $transaction->refund_status ?? 'NONE',
                'gross_sales' => (float)$transaction->gross_sales,
                'net_sales' => (float)$transaction->net_sales,
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString()
            ]
        ]);
    }
}

