<?php

namespace Database\Rollbacks;

use Illuminate\Support\Facades\DB;

class TransactionProcessingRollback
{
    public static function rollback()
    {
        // Disable new features
        config(['feature-flags.use_new_transaction_processing' => false]);
        
        // Restore previous handlers
        config(['app.transaction_handler' => 'App\Services\LegacyTransactionService']);
        
        // Clear any problematic data
        DB::table('transactions')
            ->where('created_at', '>=', now()->subHours(1))
            ->update(['status' => 'PENDING']);
    }
}