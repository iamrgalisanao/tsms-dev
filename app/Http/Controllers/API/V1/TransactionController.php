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
        Log::info('Authenticated user:', ['user' => $user]);
        $jwt = JWTAuth::parseToken();
        $payload = $jwt->getPayload();

        $payloadData = $request->all();

        // Step 1: Create log with token + IP audit info
        $log = new IntegrationLog();
        $log->tenant_id = $user->tenant_id ?? null;
        $log->terminal_id = $user->id ?? null;
        $log->request_payload = json_encode($payloadData);
        $log->status = 'FAILED';

        // 🌐 New audit fields
        $log->ip_address = $request->ip();
        $log->token_issued_at = Carbon::createFromTimestamp($payload['iat'] ?? now()->timestamp);
        $log->token_expires_at = Carbon::createFromTimestamp($payload['exp'] ?? now()->addDay()->timestamp);

        $log->save();

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
            
            // Automatically set up for retry if this terminal has retries enabled
            $terminal = \App\Models\PosTerminal::find($log->terminal_id);
            if ($terminal && $terminal->retry_enabled) {
                $log->retry_count = 0;
                $log->retry_reason = 'VALIDATION_ERROR';
                
                // Use exponential backoff with jitter for more resilient retries
                $baseInterval = $terminal->retry_interval_sec ?? 300;
                $backoffMultiplier = pow(2, $log->retry_count); 
                $jitter = mt_rand(-30, 30); // Add random jitter to prevent thundering herd
                $retryDelay = min($baseInterval * $backoffMultiplier + $jitter, 86400); // Max 24 hours
                
                $log->next_retry_at = now()->addSeconds($retryDelay);
                \Log::info("Transaction failed, scheduled for retry at {$log->next_retry_at} (delay: {$retryDelay}s)");
            }
            
            $log->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $result['errors'],
                'error_code' => $result['error_code']
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
                'tenant_id' => $user->tenant_id,
                'terminal_id' => $user->id,
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
}
