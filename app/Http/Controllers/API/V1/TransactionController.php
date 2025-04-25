<?php


namespace App\Http\Controllers\API\V1;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Transactions;
use App\Models\IntegrationLog;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Events\TransactionPermanentlyFailed;

class TransactionController extends Controller
{
    protected $validator;

    public function __construct(TransactionValidationService $validator)
    {
        $this->validator = $validator;
    }

    public function store(Request $request)
    {
        $startTime = microtime(true);

        // Generate or use existing idempotency key for retry safety
        $idempotencyKey = $request->header('Idempotency-Key') ?? 
                         ($request->input('transaction_id') ?? Str::uuid()->toString());
                         
        // Check for existing transaction with this key to prevent duplicates
        $existingTransaction = Transactions::where('transaction_id', $idempotencyKey)->first();
        if ($existingTransaction) {
            // Return the same response as before to ensure idempotency
            return response()->json([
                'status' => 'success',
                'message' => 'Transaction already processed',
                'transaction_id' => $existingTransaction->id,
                'validation_status' => $existingTransaction->validation_status,
            ], 200);
        }

        // Authenticate terminal
        $terminal = TerminalToken::where('terminal_id', $request->input('terminal_id'))
            ->latest()
            ->first();

        if (!$terminal || !$terminal->isValid()) {
            return response()->json([
                'status' => 'unauthenticated',
                'message' => 'Invalid or expired terminal token.',
            ], 401);
        }

        // Proceed with transaction logic
        $terminal = JWTAuth::parseToken()->authenticate();
        if (!$terminal) {
            Log::error('Authentication failed: POS Terminal not found');
            return response()->json(['status' => 'unauthenticated', 'message' => 'Authentication required or token invalid.'], 401);
        }
        Log::info('Authenticated terminal:', ['terminal' => $terminal]);
        $jwt = JWTAuth::parseToken();
        $payload = $jwt->getPayload();

        $payloadData = $request->all();

        // Step 1: Create log with token + IP audit info
        $log = new IntegrationLog();
        $log->tenant_id = $terminal->tenant_id ?? null;
        $log->terminal_id = $terminal->id ?? null;
        $log->request_payload = json_encode($payloadData);
        $log->status = 'FAILED';

        // 🌐 New audit fields
        $log->ip_address = $request->ip();
        $log->token_issued_at = Carbon::createFromTimestamp($payload['iat'] ?? now()->timestamp);
        $log->token_expires_at = Carbon::createFromTimestamp($payload['exp'] ?? now()->addDay()->timestamp);
        $log->idempotency_key = $idempotencyKey; // Store idempotency key for tracking

        $log->save();

        // Check for circuit breaker condition
        $recentFailures = IntegrationLog::where('status', 'FAILED')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        if ($recentFailures > 10) {
            // Circuit is open - stop immediate processing and delay retry
            $log->retry_count = 0;
            $log->retry_reason = 'CIRCUIT_BREAKER_OPEN';
            $log->next_retry_at = now()->addMinutes(15);
            $log->save();
            
            Log::warning("Circuit breaker open due to {$recentFailures} failures in last 5 minutes");
            
            return response()->json([
                'status' => 'error',
                'message' => 'Service temporarily unavailable, transaction will be retried automatically',
                'retry_at' => $log->next_retry_at
            ], 503);
        }

        // Step 2: Validate payload
        $result = $this->validator->validate($payloadData);
        $payloadData['validation_status'] = $result['validation_status'];
        $payloadData['error_code'] = $result['error_code'];
        $payloadData['payload_checksum'] = $result['computed_checksum'];

        // Step 3: Handle validation failure
        if ($result['validation_status'] === 'ERROR') {
            $log->response_payload = json_encode([
                'errors' => $result['errors'],
                'error_code' => $result['error_code']
            ]);
            $log->error_message = 'Validation failed';
            $log->http_status_code = 422;
            
            // Automatically set up for retry based on error type and terminal settings
            $errorCategory = $this->categorizeError($result['error_code'], $result['errors']);
            $this->configureRetryParams($log, $terminal, $errorCategory);
            
            $log->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $result['errors'],
                'error_code' => $result['error_code'],
                'retry_scheduled_at' => $log->next_retry_at
            ], 422);
        }

        // Step 4: Store transaction
        $transaction = Transactions::create(array_merge(
            $request->only([
                'transaction_id',
                'hardware_id',
                'transaction_timestamp',
                'gross_sales',
                'net_sales',
                'vatable_sales',
                'vat_exempt_sales',
                'vat_amount',
                'promo_discount_amount',
                'promo_status',
                'discount_total',
                'discount_details',
                'other_tax',
                'management_service_charge',
                'employee_service_charge',
                'transaction_count',
                'payload_checksum',
                'validation_status',
                'error_code',
                'store_name',
                'machine_number'
            ]),
            [
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'idempotency_key' => $idempotencyKey, // Store for future idempotency checks
            ]
        ));

        // Step 5: Finalize log with response and latency
        $endTime = microtime(true);
        $log->status = 'SUCCESS';
        $log->response_payload = json_encode([
            'transaction_id' => $transaction->id,
            'validation_status' => $payloadData['validation_status']
        ]);
        $log->http_status_code = 200;
        $log->latency_ms = round(($endTime - $startTime) * 1000);
        $log->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction recorded',
            'transaction_id' => $transaction->id,
            'validation_status' => $payloadData['validation_status'],
        ], 200);
    }

    /**
     * Categorize errors to determine appropriate retry strategy
     */
    protected function categorizeError($errorCode, $errors)
    {
        // Network or connectivity errors warrant quick retries
        if (in_array($errorCode, ['NETWORK_ERROR', 'TIMEOUT', 'CONNECTION_ERROR'])) {
            return 'NETWORK_ERROR';
        }
        
        // Data validation errors might need human intervention
        if (in_array($errorCode, ['VALIDATION_ERROR', 'SCHEMA_ERROR'])) {
            return 'VALIDATION_ERROR';
        }
        
        // Server errors should use exponential backoff
        if (in_array($errorCode, ['SERVER_ERROR', 'INTERNAL_ERROR'])) {
            return 'SERVER_ERROR';
        }
        
        // Default category
        return 'GENERAL_ERROR';
    }
    
    /**
     * Configure retry parameters based on error category and terminal settings
     */
    protected function configureRetryParams(IntegrationLog $log, $terminal, $errorCategory)
    {
        // If terminal doesn't exist or retries disabled, don't configure retry
        if (!$terminal || !$terminal->retry_enabled) {
            return;
        }
        
        // Get tenant-level settings if available, otherwise use defaults
        $tenant = \App\Models\Tenant::find($log->tenant_id);
        $maxRetries = $terminal->max_retries ?? $tenant->max_retries ?? config('app.max_retries', 3);
        
        // Initialize retry count
        $log->retry_count = 0;
        $log->retry_reason = $errorCategory;
        
        // Store retry history for debugging and analytics
        $currentHistory = json_decode($log->retry_history ?? '[]', true);
        $currentHistory[] = [
            'attempt' => $log->retry_count,
            'time' => now()->toIso8601String(),
            'reason' => $errorCategory
        ];
        $log->retry_history = json_encode($currentHistory);
        
        // Set retry timing based on error category
        switch ($errorCategory) {
            case 'NETWORK_ERROR':
                // Retry network errors quickly
                $log->next_retry_at = now()->addSeconds(60);
                break;
                
            case 'VALIDATION_ERROR':
                // Validation errors need longer delays - might need human intervention
                $log->next_retry_at = now()->addMinutes(30);
                break;
                
            case 'SERVER_ERROR':
            default:
                // Use exponential backoff with jitter for server errors
                $baseInterval = $terminal->retry_interval_sec ?? 300;
                $backoffMultiplier = pow(2, $log->retry_count);
                $jitter = mt_rand(-30, 30); // Add random jitter to prevent thundering herd
                $retryDelay = min($baseInterval * $backoffMultiplier + $jitter, 86400); // Max 24 hours
                
                $log->next_retry_at = now()->addSeconds($retryDelay);
                break;
        }
        
        Log::info("Transaction {$log->id} failed with {$errorCategory}, scheduled for retry at {$log->next_retry_at}");
        
        // Check if max retries would be exceeded
        if ($log->retry_count >= $maxRetries) {
            $log->status = 'PERMANENTLY_FAILED';
            $log->retry_reason = 'MAX_RETRIES_EXCEEDED';
            $log->next_retry_at = null;
            
            // Fire event for permanent failure
            event(new TransactionPermanentlyFailed($log));
            
            Log::warning("Transaction {$log->id} marked as permanently failed after {$log->retry_count} retries");
        }
    }
}
