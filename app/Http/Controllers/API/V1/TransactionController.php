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

            // Add detailed request logging for troubleshooting
            Log::info('Transaction API request received', [
                'payload_size' => strlen(json_encode($request->all())),
                'terminal_id' => $request->terminal_id ?? ($request->payload['terminal_id'] ?? 'missing'),
                'transaction_id' => $request->payload['transaction_id'] ?? 'missing'
            ]);

            // Get terminal and tenant info
            $terminalId = $request->terminal_id ?? ($request->payload['terminal_id'] ?? null);
            if (!$terminalId) {
                throw new \Exception('Terminal ID is required');
            }

            $terminal = PosTerminal::findOrFail($terminalId);
            
            // Prepare transaction data with more defensive checks
            $transactionData = [
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $request->payload['transaction_id'] ?? null,
                'store_name' => $request->payload['store_name'] ?? null,
                'hardware_id' => $request->payload['hardware_id'] ?? null,
                'machine_number' => $request->payload['machine_number'] ?? null,
                'transaction_timestamp' => $request->payload['transaction_timestamp'] ?? now(),
                'gross_sales' => $request->payload['gross_sales'] ?? 0,
                'net_sales' => $request->payload['net_sales'] ?? 0,
                'vatable_sales' => $request->payload['vatable_sales'] ?? 0,
                'vat_amount' => $request->payload['vat_amount'] ?? 0,
                'transaction_count' => $request->payload['transaction_count'] ?? 1,
                'validation_status' => 'PENDING',
                'job_status' => Transaction::JOB_STATUS_QUEUED,
                'job_attempts' => 0
            ];

            // Validate required fields
            $requiredFields = ['transaction_id', 'gross_sales', 'net_sales'];
            foreach ($requiredFields as $field) {
                if (empty($transactionData[$field])) {
                    throw new \Exception("Required field '{$field}' is missing or empty");
                }
            }

            // Check for duplicate transaction
            $existingTransaction = Transaction::where('transaction_id', $transactionData['transaction_id'])
                ->where('terminal_id', $terminal->id)
                ->first();
                
            if ($existingTransaction) {
                // Return the status of the existing transaction instead of an error
                return response()->json([
                    'status' => 'success',
                    'message' => 'Transaction already exists',
                    'data' => [
                        'transaction_id' => $existingTransaction->transaction_id,
                        'status' => $existingTransaction->job_status,
                        'already_processed' => true
                    ]
                ], 200);
            }

            $transaction = Transaction::create($transactionData);

            // Add system log entry
            try {
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
            } catch (\Exception $logError) {
                Log::warning('Failed to create system log, continuing transaction processing', [
                    'error' => $logError->getMessage()
                ]);
            }

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
            
            Log::error('Transaction API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['password', 'token'])
            ]);
            
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