<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * INVALID TRANSACTION ANALYSIS TOOL
 * 
 * Purpose: Investigate why all transactions in staging are marked INVALID
 * instead of VALID, preventing WebApp forwarding.
 */

echo "🔍 INVALID TRANSACTION ANALYSIS\n";
echo "===============================\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\TransactionJob;

try {
    echo "📊 TRANSACTION DETAILED ANALYSIS:\n";
    echo "----------------------------------\n";
    
    $transactions = Transaction::with(['jobs'])->get();
    
    foreach ($transactions as $tx) {
        echo "🔎 Transaction ID: {$tx->id}\n";
        echo "   UUID: " . substr($tx->transaction_id, 0, 16) . "...\n";
        echo "   Validation Status: {$tx->validation_status}\n";
        echo "   Job Status: {$tx->job_status}\n";
        echo "   Created: {$tx->created_at}\n";
        echo "   Updated: {$tx->updated_at}\n";
        
        // Check related jobs
        $jobs = $tx->jobs()->orderBy('created_at', 'desc')->get();
        if ($jobs->count() > 0) {
            echo "   📋 Related Jobs:\n";
            foreach ($jobs as $job) {
                echo "     • Job ID: {$job->id}, Status: {$job->job_status}\n";
                echo "       Type: {$job->job_type}, Created: {$job->created_at}\n";
                if ($job->error_message) {
                    echo "       ❌ Error: " . substr($job->error_message, 0, 100) . "...\n";
                }
            }
        } else {
            echo "   📋 No related jobs found\n";
        }
        
        // Check validation details if available
        if ($tx->validation_status === 'INVALID') {
            echo "   ❌ INVALID Reason Investigation:\n";
            
            // Check if there are validation error logs or details
            $recentLogs = DB::table('failed_jobs')
                ->where('payload', 'like', "%{$tx->transaction_id}%")
                ->orderBy('failed_at', 'desc')
                ->limit(3)
                ->get(['exception', 'failed_at']);
            
            if ($recentLogs->count() > 0) {
                echo "     📄 Recent Failed Jobs:\n";
                foreach ($recentLogs as $log) {
                    echo "       • Failed: {$log->failed_at}\n";
                    echo "         Error: " . substr($log->exception, 0, 150) . "...\n";
                }
            }
        }
        
        echo "\n" . str_repeat("-", 60) . "\n\n";
    }
    
    // Check the latest successful transaction from logs
    echo "🔍 SPECIFIC TRANSACTION CHECK:\n";
    echo "------------------------------\n";
    
    $specificTx = Transaction::where('transaction_id', '9861431d-afa9-4415-a7c8-f8d52b26bffd')->first();
    
    if ($specificTx) {
        echo "✅ Found the idempotency transaction from logs:\n";
        echo "   Database ID: {$specificTx->id}\n";
        echo "   Validation Status: {$specificTx->validation_status}\n";
        echo "   Job Status: {$specificTx->job_status}\n";
        echo "   Created: {$specificTx->created_at}\n";
        
        if ($specificTx->validation_status === 'INVALID') {
            echo "   ❌ This transaction is also INVALID!\n";
            echo "   🔍 This explains why it's not being forwarded despite successful API response\n";
        }
    } else {
        echo "❌ Transaction 9861431d-afa9-4415-a7c8-f8d52b26bffd not found in database\n";
        echo "   This suggests the idempotency response was from a different source\n";
    }
    
    echo "\n🎯 VALIDATION WORKFLOW ANALYSIS:\n";
    echo "=================================\n";
    
    // Check if validation jobs are running
    $validationJobs = TransactionJob::where('job_type', 'validation')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();
    
    if ($validationJobs->count() > 0) {
        echo "📋 Recent Validation Jobs:\n";
        foreach ($validationJobs as $job) {
            echo "   • Job ID: {$job->id}, Status: {$job->job_status}\n";
            echo "     Transaction: {$job->transaction_id}\n";
            echo "     Created: {$job->created_at}\n";
            if ($job->error_message) {
                echo "     Error: " . substr($job->error_message, 0, 100) . "...\n";
            }
        }
    } else {
        echo "❌ No validation jobs found - this may be the issue!\n";
        echo "   Transactions may not be going through validation pipeline\n";
    }
    
    echo "\n🔧 SENIOR DEVELOPER RECOMMENDATIONS:\n";
    echo "=====================================\n";
    
    $invalidCount = Transaction::where('validation_status', 'INVALID')->count();
    $totalCount = Transaction::count();
    
    if ($invalidCount === $totalCount && $totalCount > 0) {
        echo "❌ ALL TRANSACTIONS ARE INVALID ({$invalidCount}/{$totalCount})\n";
        echo "🔍 CRITICAL ISSUES TO INVESTIGATE:\n";
        echo "   1. Transaction validation pipeline not working\n";
        echo "   2. Validation jobs not being dispatched\n";
        echo "   3. Validation logic has a systematic error\n";
        echo "   4. Database constraint preventing status updates\n\n";
        
        echo "🛠️ IMMEDIATE ACTIONS NEEDED:\n";
        echo "   • Check validation job queue processing\n";
        echo "   • Review validation service logic\n";
        echo "   • Verify database permissions for status updates\n";
        echo "   • Test manual transaction validation\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ Error during analysis: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}
