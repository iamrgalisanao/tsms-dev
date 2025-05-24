<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessTransactionJob;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            // Get terminal and tenant info
            $terminal = PosTerminal::findOrFail($request->terminal_id);
            
            $transaction = Transaction::create([
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $request->payload['transaction_id'],
                'store_name' => $request->payload['store_name'],
                'hardware_id' => $request->payload['hardware_id'],
                'machine_number' => $request->payload['machine_number'],
                'transaction_timestamp' => $request->payload['transaction_timestamp'],
                'gross_sales' => $request->payload['gross_sales'],
                'net_sales' => $request->payload['net_sales'],
                'vatable_sales' => $request->payload['vatable_sales'],
                'vat_amount' => $request->payload['vat_amount'],
                'transaction_count' => $request->payload['transaction_count'] ?? 1,
                'validation_status' => 'PENDING',
                'job_status' => Transaction::JOB_STATUS_QUEUED,
                'job_attempts' => 0
            ]);

            // Add system log entry
            \App\Models\SystemLog::create([
                'type' => 'transaction',
                'severity' => 'info',
                'terminal_uid' => $terminal->terminal_uid,
                'transaction_id' => $transaction->transaction_id,
                'message' => 'Transaction received and queued for processing',
                'context' => json_encode([
                    'terminal_id' => $terminal->id,
                    'tenant_id' => $terminal->tenant_id,
                    'amount' => $request->payload['gross_sales']
                ])
            ]);

            // Dispatch job for processing
            ProcessTransactionJob::dispatch($transaction)->onQueue('transactions');

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction queued for processing',
                'data' => [
                    'transaction_id' => $transaction->transaction_id
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log error to system_logs
            try {
                \App\Models\SystemLog::create([
                    'type' => 'error',
                    'severity' => 'error',
                    'terminal_uid' => $request->terminal_id ? (PosTerminal::find($request->terminal_id)?->terminal_uid ?? 'unknown') : 'unknown',
                    'transaction_id' => $request->payload['transaction_id'] ?? null,
                    'message' => 'Transaction creation failed: ' . $e->getMessage(),
                    'context' => json_encode([
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
                'status' => 'error',
                'message' => 'Failed to process transaction: ' . $e->getMessage(),
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
                'validation_status' => $transaction->validation_status,
                'job_status' => $transaction->job_status,
                'job_attempts' => $transaction->job_attempts,
                'completed_at' => $transaction->completed_at,
                'errors' => $transaction->error_message ? [$transaction->error_message] : []
            ]
        ]);
    }
}