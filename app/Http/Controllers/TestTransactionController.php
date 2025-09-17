<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\RetryHistory;
use App\Models\SystemLog;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class TestTransactionController extends Controller
{
    /**
     * Show the test transaction page
     */
    public function index()
    {
        $tenants = DB::table('tenants')->get();
        $terminals = PosTerminal::with('tenant')
            ->orderBy('tenant_id')
            ->orderBy('serial_number')
            ->get()
            ->unique('id');
        
        return view('transactions.test', compact('terminals', 'tenants'));
    }
    
    /**
     * Process a test transaction
     */
    public function process(Request $request)
    {
        try {
            DB::beginTransaction();
            
            // Validate form data
            $validator = Validator::make($request->all(), [
                'terminal_id' => 'required|exists:pos_terminals,id',
                'gross_sales' => 'required|numeric|min:0.01',
                'net_sales' => 'required|numeric|min:0.01',
                'vatable_sales' => 'required|numeric|min:0',
                'vat_amount' => 'required|numeric|min:0',
                'transaction_count' => 'required|integer|min:1',
            ]);
            
            if ($validator->fails()) {
                return back()->withErrors($validator)->withInput();
            }
            
            // Get terminal
            $terminal = PosTerminal::findOrFail($request->terminal_id);
            
            // Create transaction ID if not provided
            $transactionId = $request->transaction_id ?? 'TEST-' . now()->format('Ymd-His') . '-' . rand(1000, 9999);
            
            // Check for duplicate transaction ID before creating
            $existingTransaction = Transaction::where('transaction_id', $transactionId)
                ->exists();
            
            if ($existingTransaction) {
                // If this is an AJAX request (like from bulk generation)
                if ($request->wantsJson() || $request->ajax() || $request->hasHeader('X-Requested-With')) {
                    DB::commit();
                    return response()->json([
                        'status' => 'error',
                        'message' => "Transaction ID already exists. Please use a different ID.",
                        'data' => [
                            'transaction_id' => $transactionId,
                            'duplicate' => true
                        ]
                    ], 422);
                }
                
                // For regular form submission
                DB::commit();
                return back()->with('error', "Transaction ID '{$transactionId}' already exists. Please use a different ID or leave blank to auto-generate.")
                    ->withInput();
            }
            
            // Create transaction (use new normalized schema)
            $transaction = Transaction::create([
                'customer_code' => $terminal->customer_code ?? null,
                'terminal_id' => $terminal->id,
                'transaction_id' => $transactionId,
                'trade_name' => $request->trade_name ?? null,
                'hardware_id' => $request->hardware_id ?? null,
                'machine_number' => $request->machine_number ?? null,
                'transaction_timestamp' => $request->transaction_timestamp ?? now(),
                'gross_sales' => $request->gross_sales ?? 0,
                'net_sales' => $request->net_sales ?? ($request->gross_sales ?? 0),
                'payload_checksum' => $request->payload_checksum ?? null,
                'validation_status' => 'PENDING',
                'job_status' => Transaction::JOB_STATUS_QUEUED,
                'job_attempts' => 0
            ]);
            
            // Dispatch job for processing
            ProcessTransactionJob::dispatch($transaction->id)->afterCommit();
            
            DB::commit();
            
            // Return JSON response for AJAX requests
            if ($request->wantsJson() || $request->ajax() || $request->hasHeader('X-Requested-With')) {
                return response()->json([
                    'status' => 'success',
                    'message' => "Test transaction {$transactionId} has been created and queued for processing.",
                    'data' => [
                        'transaction_id' => $transaction->transaction_id,
                        'id' => $transaction->id
                    ]
                ]);
            }
            
            return back()->with('success', "Test transaction {$transactionId} has been created and queued for processing.");
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Test transaction creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return JSON response for AJAX requests
            if ($request->wantsJson() || $request->ajax() || $request->hasHeader('X-Requested-With')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to create test transaction: ' . $e->getMessage()
                ], 422);
            }
            
            return back()->with('error', 'Failed to create test transaction: ' . $e->getMessage())->withInput();
        }
    }
    
    /**
     * View transaction details
     */
    public function show($id)
    {
        try {
            $transaction = Transaction::findOrFail($id);
            
            return view('transactions.show', compact('transaction'));
        } catch (\Exception $e) {
            Log::error('Transaction view failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('transactions.test')
                ->with('error', 'Transaction not found or could not be viewed.');
        }
    }
    
    /**
     * Retry a failed transaction
     */
    public function retryTransaction(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $transaction = Transaction::lockForUpdate()->findOrFail($id);
            
            // Log retry attempt
            SystemLog::create([
                'transaction_id' => $transaction->id,
                'log_type' => 'RETRY_ATTEMPT',
                'message' => 'Manual retry initiated',
                'context' => [
                    'attempt_number' => ($transaction->retry_count ?? 0) + 1
                ]
            ]);

            // Handle retry count
            $transaction->retry_count = $transaction->retry_count ?? 0;
            $transaction->retry_count++;
            $transaction->status = 'QUEUED';
            $transaction->save();

            // Create retry history with proper error handling
            try {
                RetryHistory::create([
                    'transaction_id' => $transaction->id,
                    'attempt_number' => $transaction->retry_count,
                    'status' => 'INITIATED',
                    'initiated_at' => now()
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create retry history', [
                    'transaction_id' => $transaction->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Dispatch job with proper queue
            ProcessTransactionJob::dispatch($transaction->id)
                ->afterCommit()
                ->onQueue('transactions')
                ->delay(now()->addSeconds(5)); // Add small delay to prevent race conditions

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Transaction queued for retry',
                'retry_count' => $transaction->retry_count
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Retry transaction failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to retry transaction: ' . $e->getMessage()
            ], 500);
        }
    }
}