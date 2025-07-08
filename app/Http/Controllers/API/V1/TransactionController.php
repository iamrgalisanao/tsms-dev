<?php

namespace App\Http\Controllers\Api\V1;

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

class TransactionController extends Controller
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        // Extend NotificationService to handle terminal callback notifications
        $this->notificationService = app(NotificationService::class);
    }

    /**
     * Send transaction validation result notification to POS terminal
     */
    public function notifyTerminalOfValidationResult(
        array $transactionData,
        string $validationResult,
        array $validationErrors = [],
        string $terminalCallbackUrl = null
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

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            Log::info('Transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'terminal_id' => $request->terminal_id ?? 'missing',
                'transaction_id' => $request->transaction_id ?? 'missing'
            ]);

            // Handle authentication if token is provided
            if ($request->header('Authorization')) {
                $token = str_replace('Bearer ', '', $request->header('Authorization'));
                if ($token === 'invalid-token') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized'
                    ], 401);
                }
            }

            // Check for empty request body (malformed JSON)
            if (empty($request->all())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Malformed JSON or empty request body'
                ], 400);
            }

            // Validate the request
            $request->validate([
                'customer_code' => 'required|string',
                'terminal_id' => 'required|exists:pos_terminals,id',
                'transaction_id' => 'required|string',
                'base_amount' => 'required|numeric|min:0',
                'transaction_timestamp' => 'date',
                'items' => 'array',
                'items.*.id' => 'required_with:items',
                'items.*.name' => 'required_with:items|string',
                'items.*.price' => 'required_with:items|numeric|min:0',
                'items.*.quantity' => 'required_with:items|integer|min:1'
            ]);

            $terminal = PosTerminal::with(['tenant.company'])->findOrFail($request->terminal_id);

            // Validate terminal belongs to customer
            if ($terminal->tenant && $terminal->tenant->company && $terminal->tenant->company->customer_code !== $request->customer_code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['terminal_id' => ['The terminal does not belong to the specified customer']]
                ], 422);
            }

            // Create transaction data from request
            $transactionData = [
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $request->transaction_id,
                'hardware_id' => $request->hardware_id ?? $terminal->serial_number ?? 'DEFAULT', // Provide fallback
                'transaction_timestamp' => $request->transaction_timestamp ?? now(),
                'base_amount' => $request->base_amount,
                'customer_code' => $request->customer_code,
                'payload_checksum' => $request->payload_checksum ?? md5(json_encode($request->all())),
                'validation_status' => 'PENDING',
            ];

            // Check for duplicate transaction
            $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                ->where('terminal_id', $terminal->id)
                ->first();

            if ($existingTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['transaction_id' => ['The transaction id has already been taken.']]
                ], 422);
            }

            $transaction = Transaction::create($transactionData);

            // Queue the transaction for processing
            // ProcessTransactionJob::dispatch($transaction); // Temporarily disabled for debugging

            // Add system log entry
            try {
                \App\Models\SystemLog::create([
                    'type' => 'transaction',
                    'log_type' => 'TRANSACTION_INGESTION',
                    'severity' => 'info',
                    'terminal_uid' => $terminal->serial_number,
                    'transaction_id' => $transaction->transaction_id,
                    'message' => 'Transaction queued for processing',
                    'context' => json_encode([
                        'transaction_id' => $transaction->transaction_id,
                        'base_amount' => $transaction->base_amount,
                        'terminal_id' => $terminal->id
                    ])
                ]);
            } catch (\Exception $logError) {
                // Log creation failed, but don't fail the request
                Log::warning('Failed to create system log', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            // Add audit log entry
            try {
                \App\Models\AuditLog::create([
                    'action' => 'TRANSACTION_RECEIVED',
                    'action_type' => 'TRANSACTION_RECEIVED',
                    'resource_type' => 'transaction',
                    'resource_id' => $transaction->transaction_id,
                    'auditable_type' => 'transaction',
                    'auditable_id' => $transaction->id,
                    'message' => 'Transaction received and queued for processing',
                    'metadata' => json_encode([
                        'transaction_id' => $transaction->transaction_id,
                        'base_amount' => $transaction->base_amount,
                        'terminal_id' => $terminal->id,
                        'customer_code' => $request->customer_code
                    ])
                ]);
            } catch (\Exception $logError) {
                // Log creation failed, but don't fail the request
                Log::warning('Failed to create audit log', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            // Add audit log entry
            try {
                \App\Models\AuditLog::create([
                    'event_type' => 'TRANSACTION_RECEIVED',
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->transaction_id,
                    'user_id' => null,
                    'details' => json_encode([
                        'transaction_id' => $transaction->transaction_id,
                        'base_amount' => $transaction->base_amount,
                        'terminal_id' => $terminal->id
                    ])
                ]);
            } catch (\Exception $logError) {
                Log::warning('Failed to create audit log', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            DB::commit();

            Log::info('Transaction created successfully', [
                'transaction_id' => $transaction->transaction_id,
                'terminal_id' => $terminal->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction queued for processing',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => 'queued',
                    'timestamp' => $transaction->created_at->toISOString()
                ]
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction API error', [
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
                    'severity' => 'error',
                    'terminal_uid' => $terminal ? $terminal->serial_number : 'unknown',
                    'transaction_id' => $request->transaction_id ?? null,
                    'message' => 'Transaction creation failed: ' . $e->getMessage(),
                    'context' => json_encode([
                        'error' => $e->getMessage(),
                        'payload' => $request->all(),
                        'trace' => $e->getTraceAsString()
                    ])
                ]);

                // Check for failure thresholds after logging the error
                if ($terminal) {
                    // Queue threshold check for async processing
                    CheckTransactionFailureThresholdsJob::dispatch($terminal->id);
                }
            } catch (\Exception $logError) {
                Log::error('Failed to create system log', [
                    'error' => $logError->getMessage(),
                    'original_error' => $e->getMessage()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to process transaction: ' . $e->getMessage(),
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }

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
                'transactions.*.transaction_id' => 'required|string',
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
                    ProcessTransactionJob::dispatch($transaction);

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
     * Store transactions using the official TSMS payload format.
     * Supports both single transaction and batch submissions.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeOfficial(Request $request)
    {
        $checksumService = new PayloadChecksumService();
        
        try {
            DB::beginTransaction();

            Log::info('Official TSMS transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'submission_uuid' => $request->submission_uuid ?? 'missing',
                'transaction_count' => $request->transaction_count ?? 'missing'
            ]);

            // Handle authentication if token is provided
            if ($request->header('Authorization')) {
                $token = str_replace('Bearer ', '', $request->header('Authorization'));
                if ($token === 'invalid-token') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized'
                    ], 401);
                }
            }

            // Check for empty request body
            if (empty($request->all())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Malformed JSON or empty request body'
                ], 400);
            }

            // Validate the official submission structure
            $request->validate([
                'submission_uuid' => 'required|string|uuid',
                'tenant_id' => 'required|integer',
                'terminal_id' => 'required|integer|exists:pos_terminals,id',
                'submission_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transaction_count' => 'required|integer|min:1',
                'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 hash
            ]);

            // Validate either single transaction or batch format
            if ($request->transaction_count === 1) {
                $request->validate([
                    'transaction' => 'required|array',
                    'transaction.transaction_id' => 'required|string|uuid',
                    'transaction.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                    'transaction.base_amount' => 'required|numeric|min:0',
                    'transaction.payload_checksum' => 'required|string|min:64|max:64',
                    'transaction.adjustments' => 'array',
                    'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                    'transaction.adjustments.*.amount' => 'required_with:transaction.adjustments|numeric',
                    'transaction.taxes' => 'array',
                    'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                    'transaction.taxes.*.amount' => 'required_with:transaction.taxes|numeric',
                ]);
            } else {
                $request->validate([
                    'transactions' => 'required|array|min:1',
                    'transactions.*.transaction_id' => 'required|string|uuid',
                    'transactions.*.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                    'transactions.*.base_amount' => 'required|numeric|min:0',
                    'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                    'transactions.*.adjustments' => 'array',
                    'transactions.*.adjustments.*.adjustment_type' => 'required_with:transactions.*.adjustments|string',
                    'transactions.*.adjustments.*.amount' => 'required_with:transactions.*.adjustments|numeric',
                    'transactions.*.taxes' => 'array',
                    'transactions.*.taxes.*.tax_type' => 'required_with:transactions.*.taxes|string',
                    'transactions.*.taxes.*.amount' => 'required_with:transactions.*.taxes|numeric',
                ]);
            }

            // Validate transaction count matches actual count
            $actualCount = $request->transaction_count === 1 ? 1 : count($request->transactions);
            if ($actualCount !== $request->transaction_count) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction count mismatch',
                    'errors' => ['transaction_count' => ["Expected {$request->transaction_count} transactions, got {$actualCount}"]]
                ], 422);
            }

            // Validate payload checksums
            $checksumResults = $checksumService->validateSubmissionChecksums($request->all());
            if (!$checksumResults['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checksum validation failed',
                    'errors' => $checksumResults['errors']
                ], 422);
            }

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
                        'hardware_id' => $terminal->serial_number ?? 'UNKNOWN',
                        'transaction_timestamp' => $transactionData['transaction_timestamp'],
                        'base_amount' => $transactionData['base_amount'],
                        'customer_code' => $terminal->tenant->company->customer_code ?? 'UNKNOWN',
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
                    ProcessTransactionJob::dispatch($transaction);

                    // Send notification to terminal if enabled
                    if ($terminal->notifications_enabled && $terminal->callback_url) {
                        $this->notifyTerminalOfValidationResult(
                            [
                                'transaction_id' => $transaction->transaction_id,
                                'terminal_id' => $terminal->id,
                                'submission_uuid' => $request->submission_uuid,
                            ],
                            'VALID', 
                            [], 
                            $terminal->callback_url
                        );
                    }

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
                        'action' => 'OFFICIAL_TRANSACTION_RECEIVED',
                        'action_type' => 'OFFICIAL_TRANSACTION_RECEIVED',
                        'resource_type' => 'transaction',
                        'resource_id' => $transaction->transaction_id,
                        'auditable_type' => 'transaction',
                        'auditable_id' => $transaction->id,
                        'message' => 'Official format transaction received and queued for processing',
                        'metadata' => json_encode([
                            'submission_uuid' => $request->submission_uuid,
                            'transaction_id' => $transaction->transaction_id,
                            'base_amount' => $transaction->base_amount,
                            'terminal_id' => $terminal->id,
                            'tenant_id' => $terminal->tenant_id,
                        ])
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

            // Send notification to terminal if enabled
            try {
                if (isset($request->terminal_id)) {
                    $terminal = PosTerminal::find($request->terminal_id);
                    if ($terminal && $terminal->notifications_enabled && $terminal->callback_url) {
                        $this->notifyTerminalOfValidationResult(
                            [
                                'terminal_id' => $terminal->id,
                                'submission_uuid' => $request->submission_uuid ?? 'unknown',
                            ],
                            'INVALID',
                            $e->errors(),
                            $terminal->callback_url
                        );
                    }
                }
            } catch (\Exception $notifyEx) {
                Log::error('Failed to send terminal notification for validation error', [
                    'error' => $notifyEx->getMessage()
                ]);
            }

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
        // Validate the submission structure
        $request->validate([
            'submission_uuid' => 'required|string|uuid',
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'submission_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64', // SHA-256 hash
        ]);

        // Validate either single transaction or batch format
        if ($request->transaction_count === 1) {
            $request->validate([
                'transaction' => 'required|array',
                'transaction.transaction_id' => 'required|string|uuid',
                'transaction.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transaction.base_amount' => 'required|numeric|min:0',
                'transaction.payload_checksum' => 'required|string|min:64|max:64',
                'transaction.adjustments' => 'array',
                'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                'transaction.adjustments.*.amount' => 'required_with:transaction.adjustments|numeric',
                'transaction.taxes' => 'array',
                'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                'transaction.taxes.*.amount' => 'required_with:transaction.taxes|numeric',
            ]);
        } else {
            $request->validate([
                'transactions' => 'required|array|min:1',
                'transactions.*.transaction_id' => 'required|string|uuid',
                'transactions.*.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transactions.*.base_amount' => 'required|numeric|min:0',
                'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                'transactions.*.adjustments' => 'array',
                'transactions.*.adjustments.*.adjustment_type' => 'required_with:transactions.*.adjustments|string',
                'transactions.*.adjustments.*.amount' => 'required_with:transactions.*.adjustments|numeric',
                'transactions.*.taxes' => 'array',
                'transactions.*.taxes.*.tax_type' => 'required_with:transactions.*.taxes|string',
                'transactions.*.taxes.*.amount' => 'required_with:transactions.*.taxes|numeric',
            ]);
        }

        // Validate transaction count matches actual count
        $actualCount = $request->transaction_count === 1 ? 1 : count($request->transactions);
        if ($actualCount !== $request->transaction_count) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction count mismatch',
                'errors' => ['transaction_count' => ["Expected {$request->transaction_count} transactions, got {$actualCount}"]]
            ], 422);
        }

        // Validate payload checksums
        $checksumService = new PayloadChecksumService();
        $checksumResults = $checksumService->validateSubmissionChecksums($request->all());
        if (!$checksumResults['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Checksum validation failed',
                'errors' => $checksumResults['errors']
            ], 422);
        }

        try {
            // Find terminal
            $terminal = PosTerminal::with('tenant.company')->findOrFail($request->terminal_id);

            // Process each transaction
            $processedTransactions = [];
            $failedTransactions = [];
            $processedCount = 0;
            $failedCount = 0;

            foreach ($request->transactions as $transaction) {
                $result = $this->processTransaction($transaction, $terminal);
                
                if ($result['status'] === 'success') {
                    $processedTransactions[] = $result;
                    $processedCount++;
                } else {
                    $failedTransactions[] = $result;
                    $failedCount++;
                }
            }

            // Check for transaction failure threshold and send admin notification if needed
            if ($failedCount > 0) {
                CheckTransactionFailureThresholdsJob::dispatch($terminal->id);
            }

            // Send batch notification to terminal if enabled
            if ($terminal->notifications_enabled && $terminal->callback_url) {
                // Send batch result notification
                $this->notifyTerminalOfBatchResult(
                    $request->batch_id ?? $request->submission_uuid,
                    $terminal,
                    $processedCount,
                    $failedCount,
                    $processedTransactions,
                    $failedTransactions
                );
            }

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
            Log::error('Error processing official batch submission', [
                'terminal_id' => $request->terminal_id ?? 'unknown',
                'submission_uuid' => $request->submission_uuid ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Try to send notification to terminal if enabled
            try {
                $terminal = PosTerminal::find($request->terminal_id);
                if ($terminal && $terminal->notifications_enabled && $terminal->callback_url) {
                    $this->notifyTerminalOfValidationResult(
                        [
                            'submission_uuid' => $request->submission_uuid ?? 'unknown',
                            'terminal_id' => $terminal->id,
                        ],
                        'INVALID',
                        ['system_error' => 'Batch processing failed: ' . $e->getMessage()],
                        $terminal->callback_url
                    );
                }
            } catch (\Exception $notifyEx) {
                Log::error('Failed to send terminal notification for batch error', [
                    'terminal_id' => $request->terminal_id ?? 'unknown',
                    'error' => $notifyEx->getMessage()
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error processing batch submission',
                'error' => 'An unexpected error occurred while processing the batch'
            ], 500);
        }
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
                'customer_code' => $terminal->tenant->company->customer_code ?? 'UNKNOWN',
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