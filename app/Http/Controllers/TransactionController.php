<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transactions;

class TransactionController extends Controller
{
    public function index()
    {
        // Fetch some recent transactions if the model exists
        $transactions = [];
        if (class_exists('App\Models\Transaction')) {
            try {
                $transactions = Transactions::latest()->take(10)->get();
            } catch (\Exception $e) {
                // Silently handle if table doesn't exist or other DB issues
            }
        }
        
        return view('dashboard.transactions', compact('transactions'));
    }
}