<?php

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
        $user = JWTAuth::parseToken()->authenticate();
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
