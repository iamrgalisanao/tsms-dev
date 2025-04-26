<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function transactions(Request $request)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return response()->json(['status' => 'unauthenticated', 'message' => 'Authentication required or token invalid.'], 401);
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
        $log = IntegrationLog::findOrFail($id);
        
        if ($log->status !== 'FAILED') {
            return response()->json(['error' => 'Only failed transactions can be retried'], 422);
        }

        // Dispatch retry job
        RetryTransactionJob::dispatch($log);

        return response()->json(['message' => 'Transaction retry queued successfully']);
    }
}
