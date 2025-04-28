<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function transactions(Request $request)
    {
        try {
            // Create a base query
            $query = IntegrationLog::query();

            // Eager load relationships only if we have records
            if ($query->count() > 0) {
                $query->with(['tenant:id,name', 'terminal:id,terminal_id']);
            }

            // Select specific fields
            $query->select([
                'id',
                'tenant_id',
                'terminal_id',
                'transaction_id',
                'status',
                'error_message',
                'http_status_code',
                'created_at',
                'updated_at'
            ]);

            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $query->where(function($q) use ($request) {
                    $q->where('transaction_id', 'like', '%' . $request->search . '%')
                      ->orWhere('error_message', 'like', '%' . $request->search . '%');
                });
            }

            $perPage = min($request->input('per_page', 10), 100);
            $transactions = $query->latest()->paginate($perPage);

            return response()->json([
                'data' => $transactions->items(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching transactions: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch transactions',
                'message' => 'Unable to retrieve transaction logs at this time'
            ], 500);
        }
    }

    public function retryTransaction($id)
    {
        $log = IntegrationLog::findOrFail($id);
        
        if ($log->status !== 'FAILED') {
            return response()->json(['error' => 'Only failed transactions can be retried'], 422);
        }

        // Dispatch retry job
        RetryTransactionJob::dispatch($log);

        return response()->json(['message' => 'Transaction retry queued successfully']);
    }
}
