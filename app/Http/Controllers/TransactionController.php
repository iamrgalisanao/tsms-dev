<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions.
     */
    public function index(Request $request)
    {
        // Apply filters
        $query = Transaction::query();
        
        if ($request->has('validation_status') && !empty($request->validation_status)) {
            $query->where('validation_status', $request->validation_status);
        }
        
        if ($request->has('terminal_id') && !empty($request->terminal_id)) {
            $query->where('terminal_id', $request->terminal_id);
        }
        
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        // Get paginated results
        $transactions = $query->with('posTerminal:id,terminal_uid')
                             ->latest()
                             ->paginate(15);
        
        // Get filter options
        $terminals = DB::table('pos_terminals')->select('id', 'terminal_uid')->get();
        
        // Use validation_status instead of status
        $statuses = DB::table('transactions')
                     ->select('validation_status')
                     ->distinct()
                     ->whereNotNull('validation_status')
                     ->pluck('validation_status')
                     ->toArray();
        
        return view('dashboard.transactions', [
            'transactions' => $transactions,
            'terminals' => $terminals,
            'statuses' => $statuses,
            'filters' => $request->all()
        ]);
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
}