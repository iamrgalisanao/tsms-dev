<?php


namespace App\Http\Controllers\API\V1;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Transactions;
use App\Models\IntegrationLog;
use App\Models\TerminalToken;
use App\Events\TransactionPermanentlyFailed;
use App\Services\TransactionValidationService;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Str;


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
        $terminal = \App\Models\TerminalToken::where('terminal_id', $request->input('terminal_id'))
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
            return response()->json([
                'status' => 'error',
                'message' => 'Service temporarily unavailable, transaction will be retried automatically',
                'retry_at' => now()->addMinutes(15)
            ], 503);
        }

        // Create initial log entry for tracking
        $log = new IntegrationLog();
        $log->tenant_id = $terminal->tenant_id ?? null;
        $log->terminal_id = $terminal->id ?? null;
        $log->transaction_id = $request->input('transaction_id') ?? $idempotencyKey;
        $log->request_payload = json_encode($request->all());
        $log->status = 'PENDING'; // Set to pending since we're queueing
        $log->source_ip = $request->ip();
        $log->log_type = 'transaction';
        $log->severity = 'info';
        $log->message = 'Transaction queued for processing';
        $log->save();

        // Determine content type of the request
        $contentType = $request->header('Content-Type', 'application/json');
        
        try {
            // Queue the transaction for processing
            $payload = $contentType === 'text/plain' ? $request->getContent() : $request->all();
            
            // Dispatch the job to process the transaction asynchronously
            ProcessTransactionJob::dispatch(
                $payload,
                $terminal->id,
                $log->transaction_id,
                $contentType,
                $idempotencyKey
            );
            
            // Update the log to indicate the transaction was queued
            $endTime = microtime(true);
            $log->http_status_code = 202; // Accepted
            $log->response_payload = json_encode([
                'message' => 'Transaction queued for processing',
                'transaction_id' => $log->transaction_id
            ]);
            $log->response_time = round(($endTime - $startTime) * 1000);
            $log->save();
            
            // Return a response indicating the transaction was accepted for processing
            return response()->json([
                'status' => 'pending',
                'message' => 'Transaction queued for processing',
                'transaction_id' => $log->transaction_id,
            ], 202);
            
        } catch (\Exception $e) {
            // Log error and return error response
            $log->status = 'FAILED';
            $log->error_message = $e->getMessage();
            $log->severity = 'error';
            $log->http_status_code = 500;
            $log->save();
            
            Log::error('Error queueing transaction', [
                'transaction_id' => $log->transaction_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue transaction',
                'error' => $e->getMessage()
            ], 500);
        }
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