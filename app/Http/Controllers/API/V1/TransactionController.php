<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessTransactionJob;
use App\Jobs\CheckTransactionFailureThresholdsJob;
use App\Services\PayloadChecksumService; // Add this import
use App\Services\NotificationService;
use App\Http\Requests\TSMSTransactionRequest;
use Laravel\Sanctum\PersonalAccessToken;

class TransactionController extends Controller
{
    /**
     * Refund a transaction
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function refund(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);
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
            'base_amount',
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
                \App\Models\TransactionAdjustment::create([
                    'transaction_id' => $transactionModel->transaction_id,
                    'adjustment_type' => $adjustment['adjustment_type'],
                    'amount' => $adjustment['amount'],
                ]);
            }
        }

        // Process taxes if present
        if (isset($transaction['taxes']) && is_array($transaction['taxes'])) {
            foreach ($transaction['taxes'] as $tax) {
                \App\Models\TransactionTax::create([
                    'transaction_id' => $transactionModel->transaction_id,
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
            if ($terminal->notifications_enabled && $terminal->callback_url) {
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

            // Validate batch request structure
            $request->validate([
                'batch_id' => 'required|string',
                'customer_code' => 'required|string',
                'terminal_id' => 'required|exists:pos_terminals,id',
                'transactions' => 'required|array|min:1',
                // Enforce UUID format for each transaction in batch
                'transactions.*.transaction_id' => 'required|string|uuid',
                'transactions.*.base_amount' => 'required|numeric|min:0',
                'transactions.*.transaction_timestamp' => 'required|date',
                'transactions.*.items' => 'required|array|min:1',
                'transactions.*.items.*.id' => 'required',
                'transactions.*.items.*.name' => 'required|string',
                'transactions.*.items.*.price' => 'required|numeric|min:0',
                'transactions.*.items.*.quantity' => 'required|integer|min:1'
            ]);

            $terminal = PosTerminal::findOrFail($request->terminal_id);
            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;

            foreach ($request->transactions as $transactionData) {
                try {
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
                        continue;
                    }

                    // Create transaction record
                    $transaction = Transaction::create([
                        'tenant_id' => $terminal->tenant_id,
                        'terminal_id' => $terminal->id,
                        'transaction_id' => $transactionData['transaction_id'],
                        'hardware_id' => $transactionData['hardware_id'] ?? null,
                        'transaction_timestamp' => $transactionData['transaction_timestamp'],
                        'base_amount' => $transactionData['base_amount'],
                        'customer_code' => $request->customer_code,
                        'payload_checksum' => $transactionData['payload_checksum'] ?? md5(json_encode($transactionData)),
                        'validation_status' => 'PENDING',
                    ]);

                    // Queue the transaction for processing
                    ProcessTransactionJob::dispatch($transaction->id)->afterCommit();

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
                            'base_amount' => $transaction->base_amount
                        ])
                    ]);

                    $processedTransactions[] = [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => 'queued',
                        'message' => 'Transaction queued for processing'
                    ];
                    $processedCount++;

                } catch (\Exception $e) {
                    Log::error('Failed to process transaction in batch', [
                        'batch_id' => $request->batch_id,
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);

                    $failedTransactions[] = [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];
                    $failedCount++;
                }
            }

            DB::commit();

            Log::info('Batch transaction processing completed', [
                'batch_id' => $request->batch_id,
                'processed_count' => $processedCount,
                'failed_count' => $failedCount
            ]);

            // Send notification if there are batch failures
            if ($failedCount > 0) {
                $this->notificationService->notifyBatchProcessingFailure(
                    $request->batch_id,
                    count($request->transactions),
                    $failedTransactions
                );
            }

            return response()->json([
                'success' => true,
                'message' => "Batch processed: {$processedCount} successful, {$failedCount} failed",
                'data' => [
                    'batch_id' => $request->batch_id,
                    'processed_count' => $processedCount,
                    'failed_count' => $failedCount,
                    'transactions' => array_merge($processedTransactions, $failedTransactions)
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Batch transaction API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['password', 'token'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process batch transactions: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
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
                'base_amount' => $transaction->base_amount,
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
                $forwardingService->forwardVoidedTransaction($payload);
            } else {
                // Fallback: send via generic forward method
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
        try {
            DB::beginTransaction();

            Log::info('Official TSMS transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'submission_uuid' => $request->submission_uuid ?? 'missing',
                'transaction_count' => $request->transaction_count ?? 'missing'
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

            if ($tokenableType !== \App\Models\PosTerminal::class || (int)$tokenableId !== (int)$request->terminal_id) {
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
                'submission_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transaction_count' => 'required|integer|min:1',
                'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 hash
            ]);

            Log::info('storeOfficial: Basic validation passed', [
                'submission_uuid' => $request->submission_uuid,
                'transaction_count' => $request->transaction_count
            ]);

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
                    $countMismatch = (int)$submission->transaction_count !== (int)$request->transaction_count;

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
                    'transaction.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                    'transaction.base_amount' => 'required|numeric|min:0',
                    'transaction.gross_sales' => 'required|numeric|min:0',
                    'transaction.promo_status' => 'required|string',
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
                    'transactions.*.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                    'transactions.*.base_amount' => 'required|numeric|min:0',
                    'transactions.*.gross_sales' => 'required|numeric|min:0',
                    'transactions.*.promo_status' => 'required|string',
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
            if (!$checksumResults['valid']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payload checksum',
                    'errors' => $checksumResults['errors']
                ], 422);
            }

            Log::info('storeOfficial: All validations passed, creating submission envelope');

            // NOW create submission envelope (status RECEIVED) - after all validations pass
            $submission = \App\Models\TransactionSubmission::create([
                'tenant_id' => $request->tenant_id,
                'terminal_id' => $request->terminal_id,
                'submission_uuid' => $request->submission_uuid,
                'submission_timestamp' => $request->submission_timestamp,
                'transaction_count' => $request->transaction_count,
                'payload_checksum' => $request->payload_checksum,
                'status' => \App\Models\TransactionSubmission::STATUS_RECEIVED,
            ]);
            
            Log::info('storeOfficial: Submission envelope created', [
                'submission_uuid' => $submission->submission_uuid,
                'terminal_id' => $submission->terminal_id,
            ]);

            Log::info('Checksum validation passed', [
                'submission_uuid'   => $request->submission_uuid,
                'transaction_count' => $request->transaction_count,
            ]);

            // NOTE: Idempotency check now handled at the top of the method
            // Proceeding with transaction processing...

            // Get terminal and validate tenant
            $terminal = PosTerminal::with(['tenant.company'])->findOrFail($request->terminal_id);
            if ($terminal->tenant_id !== $request->tenant_id) {
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
                        continue;
                    }
                    Log::info('storeOfficial: Creating transaction record', ['transaction_id' => $transactionData['transaction_id']]);
                    $transaction = Transaction::create([
                        'tenant_id' => $terminal->tenant_id,
                        'terminal_id' => $terminal->id,
                        'transaction_id' => $transactionData['transaction_id'],
                        'hardware_id' => $terminal->serial_number ?? 'UNKNOWN',
                        'transaction_timestamp' => $transactionData['transaction_timestamp'],
                        'base_amount' => $transactionData['base_amount'],
                        'gross_sales' => $transactionData['gross_sales'] ?? $transactionData['base_amount'],
                        'customer_code' => $transactionData['customer_code'] ?? ($terminal->tenant->company->customer_code ?? 'UNKNOWN'),
                        'promo_status' => $transactionData['promo_status'],
                        'payload_checksum' => $transactionData['payload_checksum'],
                        'validation_status' => 'PENDING',
                        'submission_uuid' => $request->submission_uuid,
                        'submission_timestamp' => $request->submission_timestamp,
                    ]);

                    // Process adjustments if present
                    if (isset($transactionData['adjustments']) && is_array($transactionData['adjustments'])) {
                        foreach ($transactionData['adjustments'] as $adjustment) {
                            \App\Models\TransactionAdjustment::create([
                                'transaction_id' => $transaction->transaction_id,
                                'adjustment_type' => $adjustment['adjustment_type'],
                                'amount' => $adjustment['amount'],
                            ]);
                        }
                    }

                    // Process taxes if present
                    if (isset($transactionData['taxes']) && is_array($transactionData['taxes'])) {
                        foreach ($transactionData['taxes'] as $tax) {
                            \App\Models\TransactionTax::create([
                                'transaction_id' => $transaction->transaction_id,
                                'tax_type' => $tax['tax_type'],
                                'amount' => $tax['amount'],
                            ]);
                        }
                    }

                    // Queue the transaction for processing
                    Log::info('storeOfficial: Dispatching ProcessTransactionJob', ['transaction_id' => $transaction->transaction_id]);
                    ProcessTransactionJob::dispatch($transaction->id)->afterCommit();
                    Log::info('storeOfficial: ProcessTransactionJob dispatched', ['transaction_id' => $transaction->transaction_id]);

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
                            'base_amount' => $transaction->base_amount,
                            'terminal_id' => $terminal->id,
                            'adjustments_count' => count($transactionData['adjustments'] ?? []),
                            'taxes_count' => count($transactionData['taxes'] ?? []),
                        ])
                    ]);

                    // Add audit log entry
                    \App\Models\AuditLog::create([
                        'user_id' => auth()->id(),
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
                            'base_amount' => $transaction->base_amount,
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

                    $failedTransactions[] = [
                        'transaction_id' => $transactionData['transaction_id'] ?? 'unknown',
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];
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

            // Send batch notification to terminal if enabled and there's more than one transaction
            if ($request->transaction_count > 1 && $terminal->notifications_enabled && $terminal->callback_url) {
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
                    'submission_uuid' => $request->submission_uuid,
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
            'submission_uuid' => 'required|string|uuid',
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'submission_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64',
        ])->validate();

        // Determine if it's single or batch submission
        $isSingle = $submission['transaction_count'] === 1;

        if ($isSingle) {
            validator($submission, [
                'transaction' => 'required|array',
                'transaction.transaction_id' => 'required|string|uuid',
                'transaction.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transaction.base_amount' => 'required|numeric|min:0',
                'transaction.gross_sales' => 'required|numeric|min:0',
                'transaction.promo_status' => 'required|string',
                'transaction.customer_code' => 'required|string',
                'transaction.payload_checksum' => 'required|string|min:64|max:64',
                'transaction.adjustments' => 'required|array|min:7',
                'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                'transaction.adjustments.*.amount' => 'required_with:transaction.adjustments|numeric',
                'transaction.taxes' => 'required|array|min:4',
                'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                'transaction.taxes.*.amount' => 'required_with:transaction.taxes|numeric',
            ])->validate();
        } else {
            validator($submission, [
                'transactions' => 'required|array|min:1',
                'transactions.*.transaction_id' => 'required|string|uuid',
                'transactions.*.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transactions.*.base_amount' => 'required|numeric|min:0',
                'transactions.*.gross_sales' => 'required|numeric|min:0',
                'transactions.*.promo_status' => 'required|string',
                'transactions.*.customer_code' => 'required|string',
                'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                'transactions.*.adjustments' => 'required|array|min:7',
                'transactions.*.adjustments.*.adjustment_type' => 'required_with:transactions.*.adjustments|string',
                'transactions.*.adjustments.*.amount' => 'required_with:transactions.*.adjustments|numeric',
                'transactions.*.taxes' => 'required|array|min:4',
                'transactions.*.taxes.*.tax_type' => 'required_with:transactions.*.taxes|string',
                'transactions.*.taxes.*.amount' => 'required_with:transactions.*.taxes|numeric',
            ])->validate();
        }

        // Count validation
        $actualCount = $isSingle ? 1 : count($submission['transactions']);
        if ($actualCount !== $submission['transaction_count']) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction count mismatch',
                'errors' => ['transaction_count' => ["Expected {$submission['transaction_count']} transactions, got {$actualCount}"]],
            ], 422);
        }

        // Checksum validation using raw payload
        $checksumService = new PayloadChecksumService();
        $checksumResults = $checksumService->validateSubmissionChecksumsFromRaw($rawJson);

        if (!$checksumResults['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Checksum validation failed',
                'errors' => $checksumResults['errors']
            ], 422);
        }

        try {
            // Find terminal
            $terminal = PosTerminal::with('tenant.company')->findOrFail($submission['terminal_id']);

            Log::info('storeOfficial: Terminal loaded', ['terminal_id' => $terminal->id, 'tenant_id' => $terminal->tenant_id]);

            // Normalize transaction list
            $transactions = $isSingle ? [$submission['transaction']] : $submission['transactions'];

            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;

            foreach ($transactions as $transaction) {
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
            if ($terminal->notifications_enabled && $terminal->callback_url) {
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

            // Create transaction
            $transactionModel = Transaction::create([
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $transaction['transaction_id'],
                'transaction_timestamp' => $transaction['transaction_timestamp'],
                'base_amount' => $transaction['base_amount'],
                'customer_code' => $transaction['customer_code'] ?? ($terminal->tenant->company->customer_code ?? 'UNKNOWN'),
                'promo_status' => $transaction['promo_status'],
                'payload_checksum' => $transaction['payload_checksum'] ?? '',
                'validation_status' => $validationStatus,
                'submission_uuid' => $transaction['submission_uuid'] ?? null,
            ]);
            $isSaved = true;

            // Process adjustments & taxes
            $this->processAdjustmentsAndTaxes($transactionModel, $transaction);

            // Check if terminal has notifications enabled and has a callback URL
            if ($terminal->notifications_enabled && $terminal->callback_url) {
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
            if ($terminal->notifications_enabled && $terminal->callback_url) {
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
}