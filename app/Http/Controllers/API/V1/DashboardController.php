<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function transactions(Request $request)
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return response()->json(['status' => 'unauthenticated', 'message' => 'Please log in to continue'], 401);
        }

        $query = IntegrationLog::query();

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('transaction_id', 'like', '%' . $request->search . '%')
                  ->orWhere('error_message', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = $request->input('per_page', 10);
        $transactions = $query->latest()->paginate($perPage);

        return response()->json($transactions);
    }

    public function retryTransaction($id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $log = IntegrationLog::findOrFail($id);
        
        if ($log->status !== 'FAILED') {
            return response()->json(['error' => 'Only failed transactions can be retried'], 422);
        }

        // Add retry logic here based on your existing implementation
        // For example: dispatch(new RetryTransactionJob($log));

        return response()->json(['message' => 'Transaction queued for retry']);
    }
}
