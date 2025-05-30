<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Jobs\BulkGenerateTransactionsJob;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(\App\Services\TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Display a listing of transactions.
     */
    public function index()
    {
        $transactions = Transaction::with(['terminal', 'tenant'])
            ->latest()
            ->paginate(15);

        return view('transactions.index', compact('transactions'));
    }

    /**
     * Display the specified transaction.
     */
    public function show($id)
    {
        $transaction = Transaction::with(['posTerminal:id,terminal_uid', 'tenant:id,name'])
                                 ->findOrFail($id);
        
        return view('dashboard.transaction-detail', [
            'transaction' => $transaction
        ]);
    }

    /**
     * @version 1.0.1
     * Last modified: 2025-05-21
     */
    protected function processTransaction(Request $request)
    {
        try {
            DB::beginTransaction(); // Add transaction wrapper

            // Backup current state if needed
            $backupData = Cache::remember('transaction_backup_' . $request->transaction_id, 60, function() use ($request) {
                return $request->all();
            });

            $result = $this->transactionService->process($request->all());

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Transaction processing failed', [
                'transaction_id' => $request->transaction_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle bulk transaction generation.
     */
    public function bulkGenerate(Request $request)
    {
        try {
            $validated = $request->validate([
                'count' => 'required|integer|min:1',
                'terminal_id' => 'required|exists:terminals,id',
                // Add other validation rules as needed
            ]);

            BulkGenerateTransactionsJob::dispatch(
                $validated['count'], 
                $request->except('count')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Bulk generation queued successfully'
            ]);

        } catch (\Throwable $e) {
            Log::error('Bulk generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to queue bulk generation'
            ], 500);
        }
    }
}