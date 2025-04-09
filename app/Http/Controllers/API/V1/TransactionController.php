<?php

namespace App\Http\Controllers\API\V1;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Transactions;
use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class TransactionController extends Controller
{
    public function store(Request $request)
    {

        $user = JWTAuth::parseToken()->authenticate();

        // Log raw payload first
        $log = new IntegrationLog();

        $log->tenant_id = $user->tenant_id ?? null;
        $log->terminal_id = $user->id ?? null;
        // $log->tenant_id = auth()->user()->tenant_id ?? null;
        // $log->terminal_id = auth()->user()->terminal_id ?? null;
        $log->request_payload = json_encode($request->all()); // ✅ Encode as string
        $log->status = 'FAILED'; // default
        $log->save();

        // Validate the payload
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|uuid|unique:transactions',
            'tenant_id' => 'required|exists:tenants,code',
            'hardware_id' => 'required|string',
            'transaction_timestamp' => 'required|date',
            'gross_sales' => 'required|numeric',
            'payload_checksum' => 'required|string|size:64',
        ]);

        if ($validator->fails()) {
            // $log->response_payload = ['errors' => $validator->errors()];
            $log->response_payload = json_encode(['errors' => $validator->errors()]);

            $log->status = 'FAILED';
            $log->error_message = 'Validation failed';
            $log->http_status_code = 422;
            $log->save();

            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Save transaction
        // $transaction = Transactions::create($request->all());
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
        
        $log->status = 'SUCCESS';
        $log->response_payload = json_encode(['transaction_id' => $transaction->id]);

        $log->http_status_code = 200;
        $log->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction recorded',
            'transaction_id' => $transaction->id
        ], 200);
    }
}

