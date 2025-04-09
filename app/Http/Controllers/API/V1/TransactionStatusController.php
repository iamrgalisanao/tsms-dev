<?php

// App\Http\Controllers\Api\TransactionStatusController.php

namespace App\Http\Controllers\API\V1;

use App\Models\IntegrationLog;
use Illuminate\Http\Request;

class TransactionStatusController
{
    /**
     * Display the specified transaction status.
     *
     * @param int|string $transaction_id The ID of the transaction to retrieve.
     * @return \Illuminate\Http\Response The HTTP response containing the transaction status.
     */
    public function show($transaction_id)
    {
        $log = IntegrationLog::whereJsonContains('request_payload->transaction_id', $transaction_id)->latest()->first();

        if (!$log) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return response()->json([
            'transaction_id'     => $transaction_id,
            'status'             => $log->status,
            'last_attempt_at'    => $log->updated_at,
            'http_status_code'   => $log->http_status_code,
            'retry_attempts'     => $log->retry_attempts,
            'error_message'      => $log->error_message,
            'next_retry_at'      => $log->next_retry_at,
        ]);
    }
    /**
     * Polls the transaction status based on the provided request data.
     *
     * @param \Illuminate\Http\Request $request The HTTP request instance containing input data.
     * @return \Illuminate\Http\JsonResponse The JSON response with the transaction status or error details.
     */

    public function poll(Request $request)
    {
        $request->validate([
            'transaction_id' => 'nullable|string',
            'terminal_id'    => 'nullable|integer',
            'tenant_id'      => 'nullable|integer',
        ]);

        $query = IntegrationLog::query();

        if ($request->filled('transaction_id')) {
            try {
                $query->whereJsonContains('request_payload->transaction_id', $request->transaction_id);
            } catch (\Throwable $e) {
                return response()->json([
                    'error' => 'Invalid JSON query or malformed payload',
                    'exception' => $e->getMessage()
                ], 500);
            }
        }
    

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->terminal_id);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $log = $query->latest()->first();

        if (!$log) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No transaction log matched your criteria.'
            ], 404);
        }

        return response()->json([
            'status' => $log->status,
            'http_status_code' => $log->http_status_code,
            'validation_status' => $log->validation_status,
            'retry_attempts' => $log->retry_attempts,
            'retry_reason' => $log->retry_reason,
            'last_attempt_at' => $log->updated_at,
            'next_retry_at' => $log->next_retry_at,
        ]);
    }
}

