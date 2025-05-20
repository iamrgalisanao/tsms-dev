<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Models\Transaction;
use App\Services\TransactionProcessingService;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    protected $processingService;

    public function __construct(TransactionProcessingService $processingService)
    {
        $this->processingService = $processingService;
    }

    /**
     * Store a new transaction.
     *
     * @param  \App\Http\Requests\TransactionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(TransactionRequest $request)
    {
        try {
            $result = $this->processingService->processTransaction($request->validated());
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction processing failed',
                    'errors' => $result['errors'] ?? ['System error occurred']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'validation_status' => 'PENDING',
                    'job_status' => Transaction::JOB_STATUS_QUEUED
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Transaction processing error', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Transaction processing failed',
                'errors' => ['System error occurred']
            ], 422);
        }
    }
    
    /**
     * Get notifications for a terminal.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getNotifications(Request $request)
    {
        $terminal = auth('api')->user();
        
        if (!$terminal && !app()->environment('local', 'testing')) {
            return response()->json([
                'error' => 'Unauthenticated terminal',
                'message' => 'No valid terminal authentication found'
            ], 401);
        }
        
        // Simple implementation - will be enhanced in future
        return response()->json([
            'notifications' => [],
            'timestamp' => now()->toIso8601String()
        ]);
    }

    /**
     * Get transaction status.
     */
    public function status($id)
    {
        try {
            $transaction = Transaction::where('transaction_id', $id)->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'job_status' => $transaction->job_status,
                    'validation_status' => $transaction->validation_status,
                    'completed_at' => $transaction->completed_at,
                    'attempts' => $transaction->job_attempts,
                    'error' => $transaction->last_error
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }
    }
}