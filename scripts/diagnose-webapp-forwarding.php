<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING WEBAPP FORWARDING DIAGNOSTIC TOOL
 * 
 * Purpose: Debug why WebApp forwarding shows "no_transactions" continuously
 * despite successful transaction processing in staging environment.
 */

echo "🔍 STAGING WEBAPP FORWARDING DIAGNOSTIC\n";
echo "========================================\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use App\Services\WebAppForwardingService;

try {
    echo "📊 DATABASE STATE ANALYSIS:\n";
    echo "----------------------------\n";
    
    // Total transactions
    $totalTransactions = Transaction::count();
    echo "• Total Transactions: {$totalTransactions}\n";
    
    // Transactions by validation status
    $validTransactions = Transaction::where('validation_status', 'VALID')->count();
    $invalidTransactions = Transaction::where('validation_status', 'INVALID')->count();
    $pendingTransactions = Transaction::where('validation_status', 'PENDING')->count();
    
    echo "• VALID Transactions: {$validTransactions}\n";
    echo "• INVALID Transactions: {$invalidTransactions}\n";  
    echo "• PENDING Transactions: {$pendingTransactions}\n\n";
    
    // Job status breakdown
    echo "📋 JOB STATUS BREAKDOWN:\n";
    echo "------------------------\n";
    $jobStatuses = Transaction::select('job_status', DB::raw('count(*) as count'))
        ->groupBy('job_status')
        ->get();
    
    foreach ($jobStatuses as $status) {
        $statusName = $status->job_status ?? 'NULL';
        echo "• {$statusName}: {$status->count}\n";
    }
    echo "\n";
    
    // WebApp forwarding records
    echo "🌐 WEBAPP FORWARDING RECORDS:\n";
    echo "-----------------------------\n";
    $totalForwards = WebappTransactionForward::count();
    echo "• Total Forwarding Records: {$totalForwards}\n";
    
    if ($totalForwards > 0) {
        $forwardStatuses = WebappTransactionForward::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();
        
        foreach ($forwardStatuses as $status) {
            echo "• {$status->status}: {$status->count}\n";
        }
    }
    echo "\n";
    
    // Find transactions that SHOULD be forwarded
    echo "🎯 FORWARDABLE TRANSACTIONS ANALYSIS:\n";
    echo "-------------------------------------\n";
    
    $forwardableQuery = Transaction::where('validation_status', 'VALID')
        ->whereDoesntHave('webappForward', function($q) {
            $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
        });
    
    $forwardableCount = $forwardableQuery->count();
    echo "• Transactions needing forwarding: {$forwardableCount}\n";
    
    if ($forwardableCount > 0) {
        echo "\n📝 SAMPLE FORWARDABLE TRANSACTIONS:\n";
        $samples = $forwardableQuery->limit(3)->get(['id', 'transaction_id', 'validation_status', 'job_status', 'created_at']);
        
        foreach ($samples as $tx) {
            echo "  • ID: {$tx->id}, UUID: " . substr($tx->transaction_id, 0, 8) . "...\n";
            echo "    Status: {$tx->validation_status}, Job: {$tx->job_status}\n";
            echo "    Created: {$tx->created_at}\n";
            
            // Check if it has forwarding record
            $forward = $tx->webappForward;
            if ($forward) {
                echo "    Forward Status: {$forward->status}\n";
            } else {
                echo "    Forward Status: NO_RECORD\n";
            }
            echo "\n";
        }
    }
    
    // Check for the specific transaction from logs
    echo "🔍 SPECIFIC TRANSACTION CHECK:\n";
    echo "------------------------------\n";
    $specificTx = Transaction::where('transaction_id', '9861431d-afa9-4415-a7c8-f8d52b26bffd')->first();
    
    if ($specificTx) {
        echo "• Found Transaction ID: 9861431d-afa9-4415-a7c8-f8d52b26bffd\n";
        echo "  Database ID: {$specificTx->id}\n";
        echo "  Validation Status: {$specificTx->validation_status}\n";
        echo "  Job Status: {$specificTx->job_status}\n";
        echo "  Created: {$specificTx->created_at}\n";
        
        $forward = $specificTx->webappForward;
        if ($forward) {
            echo "  Forward Record: EXISTS\n";
            echo "  Forward Status: {$forward->status}\n";
            echo "  Forward Created: {$forward->created_at}\n";
        } else {
            echo "  Forward Record: MISSING ❌\n";
        }
    } else {
        echo "• Transaction 9861431d-afa9-4415-a7c8-f8d52b26bffd: NOT FOUND ❌\n";
    }
    echo "\n";
    
    // Test the actual forwarding service query
    echo "🧪 FORWARDING SERVICE QUERY TEST:\n";
    echo "----------------------------------\n";
    
    $service = new WebAppForwardingService();
    
    // Let's manually run the same query the service uses
    $serviceQuery = Transaction::where('validation_status', 'VALID')
        ->whereDoesntHave('webappForward', function($query) {
            $query->where('status', WebappTransactionForward::STATUS_COMPLETED);
        })
        ->where('job_status', Transaction::JOB_STATUS_COMPLETED);
    
    $serviceCount = $serviceQuery->count();
    echo "• Service Query Result: {$serviceCount} transactions\n";
    
    if ($serviceCount === 0) {
        echo "• ❌ ISSUE IDENTIFIED: Service query returns 0 transactions\n";
        echo "  This explains why forwarding shows 'no_transactions'\n\n";
        
        // Let's break down the criteria
        echo "🔬 CRITERIA BREAKDOWN:\n";
        echo "----------------------\n";
        
        $validTxs = Transaction::where('validation_status', 'VALID')->count();
        echo "• Step 1 - validation_status='VALID': {$validTxs}\n";
        
        $withoutCompletedForward = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function($q) {
                $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })->count();
        echo "• Step 2 - Without completed forward: {$withoutCompletedForward}\n";
        
        $completedJobs = Transaction::where('validation_status', 'VALID')
            ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
            ->count();
        echo "• Step 3 - job_status='COMPLETED': {$completedJobs}\n";
        
        $finalCount = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function($q) {
                $q->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })
            ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
            ->count();
        echo "• Final Result: {$finalCount}\n";
        
    } else {
        echo "• ✅ Service query finds transactions - different issue\n";
    }
    
    echo "\n🎯 SENIOR DEVELOPER RECOMMENDATION:\n";
    echo "====================================\n";
    
    if ($forwardableCount === 0) {
        echo "• All transactions already forwarded or not eligible\n";
        echo "• Check if job processing is updating latest_job_status correctly\n";
        echo "• Verify transaction validation workflow\n";
    } else {
        echo "• Found {$forwardableCount} transactions that should be forwarded\n";
        echo "• WebApp forwarding service may have a logic issue\n";
        echo "• Manual forwarding test recommended\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ Error during diagnosis: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}
