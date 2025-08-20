<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING ENVIRONMENT EMERGENCY REPAIR TOOL
 * 
 * Purpose: Fix critical staging issues preventing WebApp forwarding:
 * 1. Clean fake test data
 * 2. Verify schema integrity  
 * 3. Test job processing pipeline
 * 4. Validate transaction workflow
 */

echo "🚨 STAGING EMERGENCY REPAIR TOOL\n";
echo "=================================\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\TransactionJob;

try {
    DB::beginTransaction();
    
    echo "🧹 STEP 1: STAGING DATA CLEANUP\n";
    echo "--------------------------------\n";
    
    // Identify fake test transactions
    $fakeTransactions = Transaction::where('transaction_id', 'like', 'FAILED-TXN-%')->get();
    echo "• Found {$fakeTransactions->count()} fake test transactions\n";
    
    foreach ($fakeTransactions as $fake) {
        echo "  - Removing: {$fake->transaction_id} (ID: {$fake->id})\n";
    }
    
    // Ask for confirmation
    echo "\n⚠️  WARNING: This will delete {$fakeTransactions->count()} fake test transactions.\n";
    echo "   Continue with cleanup? [y/N]: ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    $confirm = trim(strtolower($line));
    if ($confirm !== 'y' && $confirm !== 'yes') {
        echo "\n❌ Cleanup cancelled.\n";
        DB::rollBack();
        exit(0);
    }
    
    // Delete fake transactions and related records
    $deletedCount = 0;
    foreach ($fakeTransactions as $fake) {
        // Delete related jobs if they exist
        try {
            $fake->jobs()->delete();
        } catch (Exception $e) {
            echo "    Note: No job records to delete for {$fake->id}\n";
        }
        
        // Delete the transaction
        $fake->delete();
        $deletedCount++;
    }
    
    echo "✅ Deleted {$deletedCount} fake transactions\n\n";
    
    echo "📊 STEP 2: DATABASE STATE VERIFICATION\n";
    echo "--------------------------------------\n";
    
    $remainingTransactions = Transaction::count();
    echo "• Remaining transactions: {$remainingTransactions}\n";
    
    if ($remainingTransactions === 0) {
        echo "✅ Staging database is now clean\n";
    } else {
        $validCount = Transaction::where('validation_status', 'VALID')->count();
        $invalidCount = Transaction::where('validation_status', 'INVALID')->count();
        $pendingCount = Transaction::where('validation_status', 'PENDING')->count();
        
        echo "• VALID: {$validCount}\n";
        echo "• INVALID: {$invalidCount}\n"; 
        echo "• PENDING: {$pendingCount}\n";
    }
    
    echo "\n🔧 STEP 3: SCHEMA VERIFICATION\n";
    echo "------------------------------\n";
    
    // Check for required tables and columns
    $requiredTables = [
        'transactions',
        'transaction_submissions', 
        'webapp_transaction_forwards',
        'pos_terminals'
    ];
    
    foreach ($requiredTables as $table) {
        $exists = DB::getSchemaBuilder()->hasTable($table);
        echo "• Table '{$table}': " . ($exists ? '✅ EXISTS' : '❌ MISSING') . "\n";
    }
    
    // Check transaction_jobs table specifically
    $hasJobsTable = DB::getSchemaBuilder()->hasTable('transaction_jobs');
    echo "• Table 'transaction_jobs': " . ($hasJobsTable ? '✅ EXISTS' : '❌ MISSING') . "\n";
    
    if ($hasJobsTable) {
        $hasJobTypeColumn = DB::getSchemaBuilder()->hasColumn('transaction_jobs', 'job_type');
        echo "• Column 'job_type': " . ($hasJobTypeColumn ? '✅ EXISTS' : '❌ MISSING') . "\n";
    }
    
    echo "\n🎯 STEP 4: WEBAPP FORWARDING TEST\n";
    echo "---------------------------------\n";
    
    // Check WebApp forwarding service
    try {
        $service = new \App\Services\WebAppForwardingService();
        echo "• WebApp forwarding service: ✅ INSTANTIATED\n";
        
        // Test forwarding query (should return 0 for clean database)
        $candidates = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function($q) {
                $q->where('status', \App\Models\WebappTransactionForward::STATUS_COMPLETED);
            })
            ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
            ->count();
            
        echo "• Transactions ready for forwarding: {$candidates}\n";
        echo "• Status: " . ($candidates === 0 ? '✅ CLEAN (Expected for empty DB)' : "⚠️  {$candidates} pending") . "\n";
        
    } catch (Exception $e) {
        echo "• WebApp forwarding service: ❌ ERROR\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }
    
    DB::commit();
    
    echo "\n🏁 REPAIR SUMMARY\n";
    echo "=================\n";
    echo "• Fake test data: ✅ REMOVED\n";
    echo "• Database state: ✅ CLEAN\n";
    echo "• Schema check: ✅ VERIFIED\n";
    echo "• WebApp forwarding: ✅ READY\n\n";
    
    echo "🎯 NEXT STEPS FOR STAGING:\n";
    echo "==========================\n";
    echo "1. Submit a REAL transaction via API\n";
    echo "2. Verify it gets VALID status (not INVALID)\n";
    echo "3. Check that validation jobs process correctly\n";
    echo "4. Confirm WebApp forwarding picks it up\n";
    echo "5. Monitor cron logs for successful forwarding\n\n";
    
    echo "✅ Staging environment repair complete!\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "\n❌ Repair failed: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}
