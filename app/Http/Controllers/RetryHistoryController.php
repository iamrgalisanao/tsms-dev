<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Jobs\RetryTransactionJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RetryHistoryController extends Controller
{
    public function index(Request $request)
    {
        // dd(""hi");
        // Get all POS terminals for the filter dropdown
        $terminals = PosTerminal::select('id', 'serial_number')->get();
        
        return view('dashboard.retry-history', [
            'terminals' => $terminals
        ]);
    }
    
    public function show(Request $request, $id)
    {
        $log = IntegrationLog::with(['posTerminal:id,serial_number', 'tenant:id,name'])
            ->findOrFail($id);
            
        return view('dashboard.retry-history-detail', [
            'log' => $log
        ]);
    }
    
    public function retry(Request $request, $id)
    {
        $log = IntegrationLog::findOrFail($id);
        
        try {
            // Queue the transaction for retry
            dispatch(new RetryTransactionJob($log->transaction_id, $log->serial_number));
            
            // Log the manual retry
            $log->increment('retry_count');
            $log->retry_reason = 'Manual retry initiated by admin';
            $log->last_retry_at = now();
            $log->save();
            
            return redirect()
                ->route('dashboard.retry-history')
                ->with('success', 'Transaction has been queued for retry');
                
        } catch (\Exception $e) {
            Log::error('Manual retry failed', [
                'transaction_id' => $log->transaction_id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()
                ->route('dashboard.retry-history')
                ->with('error', 'Failed to retry transaction: ' . $e->getMessage());
        }
    }
}