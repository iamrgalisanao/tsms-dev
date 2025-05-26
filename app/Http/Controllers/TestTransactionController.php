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
        $terminals = PosTerminal::with('tenant')
            ->where('status', 'active')
            ->orderBy('tenant_id')
            ->orderBy('terminal_uid')
            ->get()
            ->unique('id');
        
        return view('transactions.test', compact('terminals'));
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
            
            // Create transaction
            $transaction = Transaction::create([
                'tenant_id' => $terminal->tenant_id,
                'terminal_id' => $terminal->id,
                'transaction_id' => $transactionId,
                'transaction_timestamp' => $request->transaction_timestamp ?? now(),
                'gross_sales' => $request->gross_sales,
                'net_sales' => $request->net_sales,
                'vatable_sales' => $request->vatable_sales,
                'vat_amount' => $request->vat_amount,
                'transaction_count' => $request->transaction_count,
                'validation_status' => 'PENDING',
                'job_status' => Transaction::JOB_STATUS_QUEUED,
                'job_attempts' => 0
            ]);
            
            // Dispatch job for processing
            ProcessTransactionJob::dispatch($transaction);
            
            DB::commit();
            
            return back()->with('success', "Test transaction {$transactionId} has been created and queued for processing.");
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Test transaction creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
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
            ProcessTransactionJob::dispatch($transaction)
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