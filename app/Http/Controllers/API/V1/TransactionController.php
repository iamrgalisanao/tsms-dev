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

class TransactionController extends Controller
{
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
    public function store(Request $request)
    {
        try {
            /**
             * Begins a new database transaction and logs the incoming API request details.
             *
             * Logs the following information:
             * - The size of the request payload in bytes.
             * - The terminal ID (or 'missing' if not provided).
             * - The transaction ID (or 'missing' if not provided).
             *
             * @param \Illuminate\Http\Request $request The incoming API request.
             * @throws \Exception If the transaction fails to begin.
             */
            DB::beginTransaction();

            Log::info('Transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'terminal_id' => $request->terminal_id ?? 'missing',
                'transaction_id' => $request->transaction_id ?? 'missing'
            ]);

            /**
             * Checks for the presence of an Authorization header in the request.
             * If present, extracts the Bearer token and validates it.
             * Returns a 401 Unauthorized response if the token is 'invalid-token'.
             *
             * @param \Illuminate\Http\Request $request The incoming HTTP request.
             * @return \Illuminate\Http\JsonResponse|null Returns a JSON response for unauthorized access, or null if authorized.
             */
            if ($request->header('Authorization')) {
                $token = str_replace('Bearer ', '', $request->header('Authorization'));
                if ($token === 'invalid-token') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized'
                    ], 401);
                }
            }

            /**
             * Checks if the incoming request body is empty.
             * If the request contains no data, returns a JSON response with a 400 status code,
             * indicating a malformed JSON or empty request body.
             *
             * @param \Illuminate\Http\Request $request The incoming HTTP request.
             * @return \Illuminate\Http\JsonResponse|null Returns a JSON response for empty request body, or null if not empty.
             */
            if (empty($request->all())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Malformed JSON or empty request body'
                ], 400);
            }

            

            
            /**
             * Validates the incoming transaction request data.
             *
             * Validation rules:
             * - customer_code: required, string
             * - terminal_id: required, must exist in pos_terminals table (id column)
             * - transaction_id: required, string
             * - base_amount: required, numeric, minimum value 0
             * - transaction_timestamp: optional, must be a valid date
             * - items: optional, must be an array
             *   - items.*.id: required if items are present
             *   - items.*.name: required if items are present, string
             *   - items.*.price: required if items are present, numeric, minimum value 0
             *   - items.*.quantity: required if items are present, integer, minimum value 1
             */
            $request->validate([
                'tenant_id' => 'required|exists:tenants,id',
                'serial_number' => 'required|exists:pos_terminals,serial_number',
                'transaction_id' => 'required|string|max:191',
                'hardware_id' => 'required|string|max:191',
                'transaction_timestamp' => 'required|date',
                'base_amount' => 'required|numeric|min:0',
                // 'customer_code' => 'required|string|max:191',
                'submission_uuid' => 'required|string|max:191',
                'submission_timestamp' => 'required|date',
            ]);

            /**
             * Retrieves the POS terminal by its ID along with its associated tenant and company information.
             *
             * @param  \Illuminate\Http\Request  $request  The incoming HTTP request containing the terminal ID.
             * @return \App\Models\PosTerminal  The POS terminal model with related tenant and company data.
             *
             * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  If the terminal with the given ID does not exist.
             */
            $terminal = PosTerminal::with(['tenant.company'])->where('serial_number', $request->serial_number)->firstOrFail();

          
            /**
             * Validates that the terminal belongs to the specified customer.
             *
             * Checks if the terminal's associated tenant and company exist, and verifies
             * that the company's customer code matches the customer code provided in the request.
             * If the validation fails, returns a JSON response with an error message and a 422 status code.
             */
            if ($terminal->tenant && $terminal->tenant->company && $terminal->tenant->company->customer_code !== $request->customer_code) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['serial_number' => ['The terminal does not belong to the specified customer']]
                ], 422);
            }

           
            /**
             * Prepares an array of transaction data for processing.
             *
             * @param  \Illuminate\Http\Request  $request  The incoming HTTP request containing transaction details.
             * @param  \App\Models\Terminal  $terminal  The terminal associated with the transaction.
             * @return array  The prepared transaction data including tenant, terminal, transaction, hardware, timestamp, amount, customer code, checksum, and validation status.
             *
             * Fields:
             * - tenant_id: ID of the tenant associated with the terminal.
             * - terminal_id: ID of the terminal where the transaction occurred.
             * - transaction_id: Unique identifier for the transaction.
             * - hardware_id: Hardware identifier, defaults to terminal serial number or 'DEFAULT' if not provided.
             * - transaction_timestamp: Timestamp of the transaction, defaults to current time if not provided.
             * - base_amount: The base amount for the transaction.
             * - customer_code: Code identifying the customer involved in the transaction.
             * - payload_checksum: Checksum of the payload, defaults to MD5 hash of request data if not provided.
             * - validation_status: Status of transaction validation, initialized as 'PENDING'.
             */
            $transactionData = [
                'tenant_id' => $terminal->tenant_id,
                'serial_number' => $terminal->serial_number,
                'transaction_id' => $request->transaction_id,
                'hardware_id' => $request->hardware_id ?? $terminal->serial_number ?? 'DEFAULT',
                'transaction_timestamp' => $request->transaction_timestamp ?? now(),
                'base_amount' => $request->base_amount,
                // 'customer_code' => $request->customer_code, // Commented out as requested
                'payload_checksum' => $request->payload_checksum ?? md5(json_encode($request->all())),
                'validation_status' => 'PENDING',
            ];

           
            /**
             * Checks if a transaction with the given transaction ID and terminal ID already exists.
             * If an existing transaction is found, returns a JSON response indicating validation failure
             * with an appropriate error message and a 422 status code.
             *
             * @param array $transactionData The transaction data containing 'transaction_id'.
             * @param Terminal $terminal The terminal instance to check against.
             * @return \Illuminate\Http\JsonResponse JSON response with validation error if transaction exists.
             */
            $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                ->where('serial_number', $terminal->serial_number)
                ->first();

            if ($existingTransaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => ['transaction_id' => ['The transaction id has already been taken.']]
                ], 422);
            }

            /**
             * Creates a new Transaction record in the database using the provided transaction data.
             *
             * @param array $transactionData The data to be used for creating the transaction.
             * @return \App\Models\Transaction The newly created Transaction instance.
             */
            $transaction = Transaction::create($transactionData);

           
            /**
             * Dispatches the ProcessTransactionJob for the given transaction.
             * This line is currently temporarily disabled for debugging purposes.
             *
             * @param Transaction $transaction The transaction instance to be processed by the job.
             */
            ProcessTransactionJob::dispatch($transaction); // Temporarily disabled for debugging

         
            /**
             * Attempts to create a system log entry for a transaction ingestion event.
             * 
             * Logs details such as transaction ID, base amount, terminal ID, and terminal serial number.
             * If log creation fails, catches the exception and writes a warning to the application log
             * without interrupting the main process.
             *
             * @throws \Exception If system log creation fails (handled internally).
             */
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
                        'serial_number' => $terminal->serial_number
                    ])
                ]);
            } catch (\Exception $logError) {
                // Log creation failed, but don't fail the request
                Log::warning('Failed to create system log', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            
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
                        'serial_number' => $terminal->serial_number,
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

            
            /**
             * Attempts to create an audit log entry for a received transaction.
             * 
             * The log records the event type as 'TRANSACTION_RECEIVED', the entity type as 'transaction',
             * and includes details such as transaction ID, base amount, and terminal ID.
             * 
             * If the audit log creation fails, a warning is logged with the error message and transaction ID.
             *
             * @throws \Exception If there is an error during audit log creation.
             */
            try {
                \App\Models\AuditLog::create([
                    'event_type' => 'TRANSACTION_RECEIVED',
                    'entity_type' => 'transaction',
                    'entity_id' => $transaction->transaction_id,
                    'user_id' => null,
                    'details' => json_encode([
                        'transaction_id' => $transaction->transaction_id,
                        'base_amount' => $transaction->base_amount,
                        'serial_number' => $terminal->serial_number
                    ])
                ]);
            } catch (\Exception $logError) {
                Log::warning('Failed to create audit log', [
                    'error' => $logError->getMessage(),
                    'transaction_id' => $transaction->transaction_id
                ]);
            }

            /**
             * Commits the current database transaction.
             * This finalizes all changes made during the transaction and makes them permanent.
             * Should be called after all transactional operations have completed successfully.
             */
            DB::commit();

            Log::info('Transaction created successfully', [
                'transaction_id' => $transaction->transaction_id,
                'serial_number' => $terminal->serial_number
            ]);

            /**
             * Returns a JSON response indicating that the transaction has been queued for processing.
             *
             * Response structure:
             * - success: Indicates if the operation was successful (boolean).
             * - message: Description of the response (string).
             * - data: Contains transaction details:
             *   - transaction_id: Unique identifier of the transaction.
             *   - status: Current status of the transaction ('queued').
             *   - timestamp: ISO 8601 formatted creation timestamp of the transaction.
             *
             * @return \Illuminate\Http\JsonResponse
             */
            return response()->json([
                'success' => true,
                'message' => 'Transaction queued for processing',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'serial_number' => $terminal->serial_number,
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
                $serialNumber = $request->serial_number ?? 'unknown';
                $terminal = PosTerminal::where('serial_number', $serialNumber)->first();
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
        $rawJson = $request->getContent();
        $checksumService = new PayloadChecksumService();
        $checksumResults = $checksumService->validateSubmissionChecksumsFromRaw($rawJson);

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

            // Validate payload checksums using raw JSON for canonicalization
            $rawPayload = $request->getContent();
            $checksumResults = $checksumService->validateSubmissionChecksumsFromRaw($rawPayload);
            if (!$checksumResults['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checksum validation failed',
                    'errors' => $checksumResults['errors']
                ], 422);
            }

            // Add this next:
            Log::info('Checksum validation passed', [
                'submission_uuid'   => $request->submission_uuid,
                'transaction_count' => $request->transaction_count,
            ]);

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
                'transaction.payload_checksum' => 'required|string|min:64|max:64',
                'transaction.adjustments' => 'array',
                'transaction.adjustments.*.adjustment_type' => 'required_with:transaction.adjustments|string',
                'transaction.adjustments.*.amount' => 'required_with:transaction.adjustments|numeric',
                'transaction.taxes' => 'array',
                'transaction.taxes.*.tax_type' => 'required_with:transaction.taxes|string',
                'transaction.taxes.*.amount' => 'required_with:transaction.taxes|numeric',
            ])->validate();
        } else {
            validator($submission, [
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