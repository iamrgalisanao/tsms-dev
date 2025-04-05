<?php

namespace App\Http\Controllers\API\V1;

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Transaction;
use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // Log raw payload first
        $log = new IntegrationLog();
        $log->tenant_id = auth()->user()->tenant_id ?? null;
        $log->terminal_id = auth()->user()->terminal_id ?? null;
        $log->request_payload = $request->all();
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
            $log->response_payload = ['errors' => $validator->errors()];
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
        $transaction = Transaction::create($request->all());
        $log->status = 'SUCCESS';
        $log->response_payload = ['transaction_id' => $transaction->id];
        $log->http_status_code = 200;
        $log->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction recorded',
            'transaction_id' => $transaction->id
        ], 200);
    }
}

