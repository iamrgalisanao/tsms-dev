<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING WEBAPP FORWARDING DIAGNOSTIC TOOL
 * 
 * Purpose: Diagnose why staging server WebApp forwarding shows "no_transactions"
 * despite successful transaction processing (Transaction ID 8).
 * 
 * Deploy this script to staging server and run to identify the root cause.
 */

echo "🔍 STAGING WEBAPP FORWARDING DIAGNOSTIC\n";
echo "========================================\n";
echo "Environment: " . app()->environment() . "\n";
echo "Timestamp: " . now() . "\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use App\Services\WebAppForwardingService;

try {
    echo "📊 STAGING DATABASE STATE ANALYSIS:\n";
    echo "------------------------------------\n";
    
    $totalTransactions = Transaction::count();
    echo "• Total Transactions: {$totalTransactions}\n";
    
    if ($totalTransactions === 0) {
        echo "❌ NO TRANSACTIONS FOUND IN STAGING DATABASE!\n";
        echo "   This explains the 'no_transactions' issue.\n";
        echo "   Expected: Transaction ID 8 from logs should exist.\n\n";
        exit(1);
    }
    
    // Transaction status breakdown
    $validCount = Transaction::where('validation_status', 'VALID')->count();
    $invalidCount = Transaction::where('validation_status', 'INVALID')->count();
    $pendingCount = Transaction::where('validation_status', 'PENDING')->count();
    
    echo "• VALID Transactions: {$validCount}\n";
    echo "• INVALID Transactions: {$invalidCount}\n";
    echo "• PENDING Transactions: {$pendingCount}\n\n";
    
    // Job status breakdown  
    echo "📋 JOB STATUS ANALYSIS:\n";
    echo "-----------------------\n";
    $jobStatuses = Transaction::select('job_status', DB::raw('count(*) as count'))
        ->groupBy('job_status')
        ->get();
    
    foreach ($jobStatuses as $status) {
        $statusName = $status->job_status ?? 'NULL';
        echo "• {$statusName}: {$status->count}\n";
    }
    echo "\n";
    
    // WebApp forwarding records analysis
    echo "🌐 WEBAPP FORWARDING RECORDS:\n";
    echo "-----------------------------\n";
    $totalForwards = WebappTransactionForward::count();
    echo "• Total Forward Records: {$totalForwards}\n";
    
    if ($totalForwards > 0) {
        $forwardStatuses = WebappTransactionForward::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();
        
        foreach ($forwardStatuses as $status) {
            echo "• Status '{$status->status}': {$status->count}\n";
        }
    }
    echo "\n";
    
    // Check for Transaction ID 8 specifically (from staging logs)
    echo "🔍 TRANSACTION ID 8 ANALYSIS (From Staging Logs):\n";
    echo "--------------------------------------------------\n";
    
    $tx8 = Transaction::find(8);
    if ($tx8) {
        echo "✅ Transaction ID 8 EXISTS:\n";
        echo "   UUID: {$tx8->transaction_id}\n";
        echo "   Validation Status: {$tx8->validation_status}\n";
        echo "   Job Status: {$tx8->job_status}\n";
        echo "   Created: {$tx8->created_at}\n";
        echo "   Updated: {$tx8->updated_at}\n";
        
        // Check if it has a forward record
        $forward = $tx8->webappForward;
        if ($forward) {
            echo "   Forward Record: ✅ EXISTS\n";
            echo "   Forward Status: {$forward->status}\n";
            echo "   Forward Created: {$forward->created_at}\n";
            
            if ($forward->status === WebappTransactionForward::STATUS_COMPLETED) {
                echo "   ✅ Already forwarded - explains why cron finds 'no_transactions'\n";
            } else {
                echo "   ⚠️  Forward incomplete - should be eligible for retry\n";
            }
        } else {
            echo "   Forward Record: ❌ MISSING\n";
            if ($tx8->validation_status === 'VALID' && $tx8->job_status === Transaction::JOB_STATUS_COMPLETED) {
                echo "   🚨 ISSUE: Should be eligible for forwarding!\n";
            } else {
                echo "   Reason: validation_status='{$tx8->validation_status}', job_status='{$tx8->job_status}'\n";
            }
        }
    } else {
        echo "❌ Transaction ID 8 NOT FOUND!\n";
        echo "   This contradicts staging logs showing 'existing_id':8\n";
        echo "   Possible causes:\n";
        echo "   - Database connection issue\n";
        echo "   - Wrong database/environment\n";
        echo "   - Transaction was deleted\n";
    }
    echo "\n";
    
    // Test the exact forwarding service query
    echo "🧪 WEBAPP FORWARDING SERVICE QUERY TEST:\n";
    echo "----------------------------------------\n";
    
    try {
        $service = new WebAppForwardingService();
        
        // Run the exact same query the service uses
        $eligibleTransactions = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function($query) {
                $query->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })
            ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
            ->get();
        
        echo "• Query Result: {$eligibleTransactions->count()} transactions eligible\n";
        
        if ($eligibleTransactions->count() === 0) {
            echo "• ❌ EXPLAINS 'no_transactions' in cron logs\n\n";
            
            // Break down the query criteria step by step
            echo "🔬 QUERY CRITERIA BREAKDOWN:\n";
            echo "----------------------------\n";
            
            $step1 = Transaction::where('validation_status', 'VALID')->count();
            echo "• Step 1 - validation_status='VALID': {$step1}\n";
            
            $step2 = Transaction::where('validation_status', 'VALID')
                ->whereDoesntHave('webappForward', function($q) {
                    $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
                })->count();
            echo "• Step 2 - Without completed forward: {$step2}\n";
            
            $step3 = Transaction::where('validation_status', 'VALID')
                ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
                ->count();
            echo "• Step 3 - job_status='COMPLETED': {$step3}\n";
            
            $final = Transaction::where('validation_status', 'VALID')
                ->whereDoesntHave('webappForward', function($q) {
                    $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
                })
                ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
                ->count();
            echo "• Final Combined Query: {$final}\n\n";
            
            // Identify the bottleneck
            if ($step1 === 0) {
                echo "🚨 ROOT CAUSE: No VALID transactions!\n";
                echo "   All transactions are INVALID or PENDING\n";
                echo "   Check validation pipeline\n";
            } elseif ($step3 === 0) {
                echo "🚨 ROOT CAUSE: No COMPLETED job status!\n";
                echo "   Transactions not completing job processing\n";  
                echo "   Check job queue and processing\n";
            } elseif ($step2 < $step1) {
                echo "✅ NORMAL: Some transactions already forwarded\n";
                echo "   Forwarding working as expected\n";
            }
            
        } else {
            echo "• ✅ Found eligible transactions - different issue\n";
            echo "• Sample eligible transactions:\n";
            foreach ($eligibleTransactions->take(3) as $tx) {
                echo "    - ID: {$tx->id}, UUID: " . substr($tx->transaction_id, 0, 16) . "...\n";
                echo "      Status: {$tx->validation_status}, Job: {$tx->job_status}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ WebApp Forwarding Service Error:\n";
        echo "   " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
    
    echo "\n🎯 STAGING ENVIRONMENT RECOMMENDATIONS:\n";
    echo "=======================================\n";
    
    if ($totalTransactions === 0) {
        echo "❌ CRITICAL: No transactions in staging database\n";
        echo "   • Submit test transactions via API\n";
        echo "   • Verify database connectivity\n";
        echo "   • Check environment configuration\n";
    } elseif ($validCount === 0) {
        echo "❌ CRITICAL: No VALID transactions found\n";
        echo "   • Fix transaction validation pipeline\n";
        echo "   • Check validation job processing\n";  
        echo "   • Review validation service logic\n";
    } elseif ($tx8 && $tx8->validation_status !== 'VALID') {
        echo "⚠️  Transaction ID 8 is not VALID (Status: {$tx8->validation_status})\n";
        echo "   • Check why validation failed\n";
        echo "   • Review transaction processing logs\n";
    } elseif ($tx8 && $tx8->job_status !== Transaction::JOB_STATUS_COMPLETED) {
        echo "⚠️  Transaction ID 8 job not completed (Status: {$tx8->job_status})\n"; 
        echo "   • Check job queue processing\n";
        echo "   • Review job execution logs\n";
    } else {
        echo "✅ Transactions appear properly configured\n";
        echo "   • WebApp forwarding may be working correctly\n";
        echo "   • 'no_transactions' might be expected behavior\n";
    }
    
    echo "\n📋 NEXT STEPS:\n";
    echo "==============\n";
    echo "1. Deploy this script to staging server\n";
    echo "2. Run: php scripts/staging-webapp-diagnostic.php\n";  
    echo "3. Compare results with local environment\n";
    echo "4. Submit fresh transaction to staging for testing\n";
    echo "5. Monitor WebApp forwarding behavior\n";
    
} catch (Exception $e) {
    echo "\n❌ Diagnostic Error: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n🔧 Possible fixes:\n";
    echo "   • Check database connection\n";
    echo "   • Verify Laravel environment setup\n"; 
    echo "   • Review model relationships\n";
}
