<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class SandboxTransactionController extends Controller
{
    /**
     * Accept and process a simulated transaction from a sandbox terminal.
     */
    public function store(Request $request)
    {
        $terminal = JWTAuth::parseToken()->authenticate();

        if (!$terminal || !$terminal->is_sandbox) {
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Only sandbox terminals are allowed.',
            ], 403);
        }

        // Log the payload for reference but skip writing to transactions table
        $log = new IntegrationLog();
        $log->tenant_id = $terminal->tenant_id ?? null;
        $log->terminal_id = $terminal->id;
        $log->request_payload = json_encode($request->all());
        $log->status = 'SANDBOX';
        $log->response_payload = json_encode([
            'message' => 'Simulated transaction accepted',
            'transaction_id' => $request->input('transaction_id'),
        ]);
        $log->retry_reason = 'SANDBOX_MODE';
        $log->save();

        Log::info('[SANDBOX] Transaction received', [
            'terminal_id' => $terminal->id,
            'transaction_id' => $request->input('transaction_id'),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Transaction received in sandbox mode.',
            'sandbox' => true,
            'transaction_id' => $request->input('transaction_id'),
        ]);
    }
}

