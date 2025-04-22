<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Transactions;
use App\Models\IntegrationLog;
use App\Services\TransactionValidationService;

class TransactionController extends Controller
{
    protected $validator;

    public function __construct(TransactionValidationService $validator)
    {
        $this->validator = $validator;
    }

    public function store(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $payload = $request->all();

        // Create log first
        $log = new IntegrationLog();
        $log->tenant_id = $user->tenant_id ?? null;
        $log->terminal_id = $user->id ?? null;
        $log->request_payload = json_encode($payload);
        $log->status = 'FAILED';
        $log->save();

        // Run validation using service
        $validation = $this->validator->validate($payload);

        // Attach validation results to payload
        $payload['validation_status'] = $validation['validation_status'];
        $payload['error_code'] = $validation['error_code'];
        $payload['payload_checksum'] = $validation['computed_checksum']; // override or confirm

        // If invalid, log and return 422
        if ($validation['validation_status'] === 'ERROR') {
            $log->response_payload = json_encode([
                'errors' => $validation['errors'],
                'error_code' => $validation['error_code']
            ]);
            $log->error_message = 'Validation failed';
            $log->http_status_code = 422;
            $log->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validation['errors'],
                'error_code' => $validation['error_code'],
            ], 422);
        }

        // Store transaction
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

        // Update log with success status
        $log->status = 'SUCCESS';
        $log->response_payload = json_encode([
            'transaction_id' => $transaction->id,
            'validation_status' => $payload['validation_status']
        ]);
        $log->http_status_code = 200;
        $log->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction recorded',
            'transaction_id' => $transaction->id,
            'validation_status' => $payload['validation_status'],
        ], 200);
    }
}
