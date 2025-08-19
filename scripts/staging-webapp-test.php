<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING WEBAPP FORWARDING MANUAL TEST
 * 
 * Purpose: Manually trigger WebApp forwarding on staging to see detailed results
 * and identify why automated cron shows "no_transactions".
 * 
 * This simulates the exact cron job behavior but with detailed logging.
 */

echo "🧪 STAGING WEBAPP FORWARDING MANUAL TEST\n";
echo "=========================================\n";
echo "Environment: " . app()->environment() . "\n";
echo "Timestamp: " . now() . "\n\n";

use Illuminate\Support\Facades\Log;
use App\Services\WebAppForwardingService;
use App\Models\Transaction;
use App\Models\WebappTransactionForward;

try {
    echo "🚀 MANUAL WEBAPP FORWARDING EXECUTION:\n";
    echo "--------------------------------------\n";
    
    // Instantiate the service
    $service = new WebAppForwardingService();
    echo "• WebApp Forwarding Service: ✅ CREATED\n";
    
    // Run the forwarding process manually
    echo "• Starting forwarding process...\n\n";
    
    // Capture the result  
    $result = $service->forwardPendingTransactions();
    
    echo "📊 FORWARDING RESULTS:\n";
    echo "----------------------\n";
    echo "• Success: " . ($result['success'] ? '✅ YES' : '❌ NO') . "\n";
    echo "• Forwarded Count: {$result['forwarded_count']}\n";
    
    if (isset($result['reason'])) {
        echo "• Reason: {$result['reason']}\n";
    }
    
    if (isset($result['errors'])) {
        echo "• Errors: " . json_encode($result['errors']) . "\n";
    }
    
    if (isset($result['details'])) {
        echo "• Details: " . json_encode($result['details']) . "\n";
    }
    
    echo "\n🔍 DETAILED ANALYSIS:\n";
    echo "---------------------\n";
    
    if ($result['forwarded_count'] === 0) {
        echo "❌ ZERO TRANSACTIONS FORWARDED\n";
        echo "   This matches the cron log behavior\n\n";
        
        // Let's manually check what the service is looking for
        echo "🔬 MANUAL TRANSACTION QUERY:\n";
        echo "----------------------------\n";
        
        $candidates = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function($query) {
                $query->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })
            ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
            ->get(['id', 'transaction_id', 'validation_status', 'job_status', 'created_at']);
        
        echo "• Found {$candidates->count()} candidate transactions\n";
        
        if ($candidates->count() > 0) {
            echo "• 🚨 MISMATCH: Manual query finds transactions, but service doesn't!\n";
            echo "• Possible service logic issue\n\n";
            
            echo "📋 CANDIDATE TRANSACTIONS:\n";
            foreach ($candidates as $tx) {
                echo "   • ID: {$tx->id}\n";
                echo "     UUID: " . substr($tx->transaction_id, 0, 16) . "...\n";
                echo "     Status: {$tx->validation_status}\n";
                echo "     Job: {$tx->job_status}\n";
                echo "     Created: {$tx->created_at}\n";
                
                // Check forward record
                $forward = $tx->webappForward;
                if ($forward) {
                    echo "     Forward: {$forward->status}\n";
                } else {
                    echo "     Forward: NONE\n";
                }
                echo "\n";
            }
        } else {
            echo "• ✅ CONSISTENT: Both service and manual query find 0 transactions\n";
            echo "• This explains the 'no_transactions' behavior\n";
        }
        
    } else {
        echo "✅ TRANSACTIONS FORWARDED SUCCESSFULLY\n";
        echo "   This suggests the service is working\n";
        echo "   Cron 'no_transactions' may be due to timing\n";
    }
    
    // Test with a specific transaction if it exists
    echo "\n🔍 TRANSACTION ID 8 FORWARDING TEST:\n";
    echo "------------------------------------\n";
    
    $tx8 = Transaction::find(8);
    if ($tx8) {
        echo "• Transaction ID 8: ✅ FOUND\n";
        echo "  UUID: {$tx8->transaction_id}\n";
        echo "  Validation: {$tx8->validation_status}\n";
        echo "  Job Status: {$tx8->job_status}\n";
        
        // Check if it meets forwarding criteria
        $isEligible = $tx8->validation_status === 'VALID' && 
                     $tx8->job_status === Transaction::JOB_STATUS_COMPLETED &&
                     !$tx8->webappForward()->where('status', WebappTransactionForward::STATUS_COMPLETED)->exists();
        
        echo "  Eligible for forwarding: " . ($isEligible ? '✅ YES' : '❌ NO') . "\n";
        
        if (!$isEligible) {
            $reasons = [];
            if ($tx8->validation_status !== 'VALID') {
                $reasons[] = "validation_status is '{$tx8->validation_status}' (need VALID)";
            }
            if ($tx8->job_status !== Transaction::JOB_STATUS_COMPLETED) {
                $reasons[] = "job_status is '{$tx8->job_status}' (need COMPLETED)";
            }
            if ($tx8->webappForward()->where('status', WebappTransactionForward::STATUS_COMPLETED)->exists()) {
                $reasons[] = "already has completed forward record";
            }
            
            echo "  Reasons ineligible:\n";
            foreach ($reasons as $reason) {
                echo "    - {$reason}\n";
            }
        }
        
    } else {
        echo "• Transaction ID 8: ❌ NOT FOUND\n";
        echo "  This contradicts staging logs\n";
    }
    
    echo "\n🎯 STAGING CONCLUSIONS:\n";
    echo "=======================\n";
    
    if ($result['forwarded_count'] === 0 && isset($result['reason']) && $result['reason'] === 'no_transactions') {
        echo "✅ BEHAVIOR CONFIRMED: Service correctly reports 'no_transactions'\n";
        echo "   This is the expected behavior when:\n";
        echo "   • No VALID transactions exist, OR\n";
        echo "   • All VALID transactions already forwarded, OR\n";  
        echo "   • No transactions have COMPLETED job status\n\n";
        
        echo "🔧 RECOMMENDATIONS:\n";
        echo "   1. Submit fresh transaction to staging\n";
        echo "   2. Ensure it reaches VALID + COMPLETED status\n";
        echo "   3. Monitor next cron run for forwarding\n";
        echo "   4. If still 'no_transactions', check service logic\n";
        
    } else {
        echo "⚠️  UNEXPECTED BEHAVIOR DETECTED\n";
        echo "   Review service implementation\n";
        echo "   Compare with cron log results\n";
    }
    
    echo "\n📋 DEPLOYMENT INSTRUCTIONS:\n";
    echo "===========================\n";
    echo "1. Upload this script to staging server\n";
    echo "2. Run: php scripts/staging-webapp-test.php\n";
    echo "3. Compare output with cron logs\n";
    echo "4. Submit test transaction if needed\n";
    echo "5. Re-run to verify forwarding works\n";
    
} catch (Exception $e) {
    echo "\n❌ Test Error: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    // Log the error for debugging  
    Log::error('Staging WebApp forwarding test failed', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    echo "\n🔧 TROUBLESHOOTING:\n";
    echo "   • Check staging environment configuration\n";
    echo "   • Verify database connectivity\n";
    echo "   • Review Laravel logs for details\n";
}
