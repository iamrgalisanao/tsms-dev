<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Jobs\RetryTransactionJob;
use App\Services\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RetryHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = IntegrationLog::with(['posTerminal:id,terminal_uid', 'tenant:id,name'])
            ->whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->select([
                'id',
                'transaction_id',
                'terminal_id',
                'tenant_id',
                'status',
                'retry_count',
                'retry_reason',
                'response_time',        // For Feature #3: duration tracking
                'retry_success',        // For Feature #3: success/failure result
                'last_retry_at',        // For Feature #3: timestamp of retry attempts
                'created_at',
                'updated_at'
            ]);

        // Feature #5: Enhanced filtering capabilities
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }
        
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $paginator = $query->latest()->paginate($request->input('per_page', 10));

        // Feature #6: Include basic retry analytics in the response
        $analytics = [
            'total_retries' => IntegrationLog::whereNotNull('retry_count')->sum('retry_count'),
            'success_rate' => $this->calculateSuccessRate(),
            'avg_response_time' => IntegrationLog::whereNotNull('response_time')->avg('response_time'),
        ];

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total()
            ],
            'analytics' => $analytics // Feature #6: Basic analytics
        ]);
    }
    
    // Feature #8: Implement manual retry option
    public function retrigger($id)
    {
        $log = IntegrationLog::findOrFail($id);
        
        // Check if circuit breaker allows the retry
        $circuitBreaker = new CircuitBreaker('transaction_service');
        if (!$circuitBreaker->isAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot retry transaction - circuit breaker is open'
            ], 503);
        }

        try {
            // Queue the transaction for retry
            dispatch(new RetryTransactionJob($log->transaction_id, $log->terminal_id));
            
            // Log the manual retry
            $log->increment('retry_count');
            $log->retry_reason = 'Manual retry initiated by admin';
            $log->last_retry_at = now();
            $log->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Transaction queued for manual retry',
                'transaction_id' => $log->transaction_id
            ]);
        } catch (\Exception $e) {
            Log::error('Manual retry failed', [
                'transaction_id' => $log->transaction_id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retry transaction: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // Feature #6: Enhanced retry analytics
    public function getAnalytics(Request $request)
    {
        // Filter by date range if provided
        $query = IntegrationLog::whereNotNull('retry_count');
        
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        if ($request->has('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }
        
        // Get success vs failure counts
        $totalRetries = $query->sum('retry_count');
        $successfulRetries = $query->where('retry_success', true)->count();
        $failedRetries = $query->where('retry_success', false)->count();
        
        // Get retry time distribution
        $avgResponseTime = $query->whereNotNull('response_time')->avg('response_time');
        $maxResponseTime = $query->whereNotNull('response_time')->max('response_time');
        
        // Get retry counts by terminal
        $retriesByTerminal = IntegrationLog::whereNotNull('retry_count')
            ->join('pos_terminals', 'integration_logs.terminal_id', '=', 'pos_terminals.id')
            ->selectRaw('pos_terminals.terminal_uid, SUM(integration_logs.retry_count) as total_retries')
            ->groupBy('pos_terminals.terminal_uid')
            ->orderByDesc('total_retries')
            ->limit(5)
            ->get();
            
        // Get retry reasons
        $retryReasons = IntegrationLog::whereNotNull('retry_reason')
            ->selectRaw('retry_reason, COUNT(*) as count')
            ->groupBy('retry_reason')
            ->orderByDesc('count')
            ->limit(5)
            ->get();
            
        return response()->json([
            'total_retries' => $totalRetries,
            'success_rate' => $this->calculateSuccessRate($query),
            'avg_response_time' => $avgResponseTime,
            'max_response_time' => $maxResponseTime,
            'retries_by_terminal' => $retriesByTerminal,
            'retry_reasons' => $retryReasons,
            'success_vs_failure' => [
                'successful' => $successfulRetries, 
                'failed' => $failedRetries
            ]
        ]);
    }
    
    // Feature #4: Retry configuration
    public function getRetryConfig()
    {
        return response()->json([
            'max_retry_attempts' => config('retry.max_attempts', 3),
            'retry_delay' => config('retry.delay', 60), // seconds
            'backoff_multiplier' => config('retry.backoff_multiplier', 2),
            'circuit_breaker_threshold' => config('retry.circuit_breaker_threshold', 5)
        ]);
    }
    
    // Enhanced helper method for analytics with query parameter
    private function calculateSuccessRate($query = null)
    {
        if (!$query) {
            $query = IntegrationLog::whereNotNull('retry_count');
        }
        
        $total = (clone $query)->count();
        if ($total === 0) return 0;
        
        $success = (clone $query)->where('status', 'SUCCESS')->count();
        return round(($success / $total) * 100, 2);
    }
}