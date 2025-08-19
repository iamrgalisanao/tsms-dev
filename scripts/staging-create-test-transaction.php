<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING TEST TRANSACTION CREATOR
 * 
 * Purpose: Create a properly formatted test transaction on staging server
 * to verify the complete WebApp forwarding pipeline works.
 * 
 * This creates a transaction that should go through:
 * 1. Validation (VALID status)
 * 2. Job processing (COMPLETED status)  
 * 3. WebApp forwarding eligibility
 */

echo "🧪 STAGING TEST TRANSACTION CREATOR\n";
echo "===================================\n";
echo "Environment: " . app()->environment() . "\n";
echo "Timestamp: " . now() . "\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\PosTerminal;

try {
    DB::beginTransaction();
    
    echo "🔧 CREATING TEST TRANSACTION:\n";
    echo "-----------------------------\n";
    
    // Get the first available terminal
    $terminal = PosTerminal::with('tenant')->first();
    if (!$terminal) {
        echo "❌ No POS terminals found in staging\n";
        echo "   Create a terminal first or check database\n";
        exit(1);
    }
    
    echo "• Using Terminal ID: {$terminal->id}\n";
    echo "• Tenant ID: {$terminal->tenant_id}\n";
    
    // Generate test transaction data
    $transactionId = \Illuminate\Support\Str::uuid()->toString();
    $testData = [
        'tenant_id' => $terminal->tenant_id,
        'terminal_id' => $terminal->id,
        'transaction_id' => $transactionId,
        'transaction_timestamp' => now(),
        'base_amount' => 99.99,
        'validation_status' => 'VALID',  // Set directly to VALID for testing
        'job_status' => Transaction::JOB_STATUS_COMPLETED,  // Set to COMPLETED for forwarding
        'raw_payload' => json_encode([
            'transaction_id' => $transactionId,
            'base_amount' => 99.99,
            'test' => true,
            'created_for' => 'webapp_forwarding_test'
        ])
    ];
    
    echo "• Transaction UUID: {$transactionId}\n";
    echo "• Amount: \$99.99\n";
    echo "• Status: VALID + COMPLETED (ready for forwarding)\n";
    
    // Create the transaction
    $transaction = Transaction::create($testData);
    echo "• Database ID: {$transaction->id}\n";
    
    DB::commit();
    
    echo "\n✅ TEST TRANSACTION CREATED SUCCESSFULLY\n\n";
    
    echo "📊 TRANSACTION DETAILS:\n";
    echo "-----------------------\n";
    echo "• ID: {$transaction->id}\n";
    echo "• UUID: {$transaction->transaction_id}\n";
    echo "• Validation Status: {$transaction->validation_status}\n";
    echo "• Job Status: {$transaction->job_status}\n";
    echo "• Created: {$transaction->created_at}\n";
    echo "• Terminal: {$transaction->terminal_id}\n";
    echo "• Tenant: {$transaction->tenant_id}\n";
    
    // Check if it's eligible for forwarding
    echo "\n🔍 FORWARDING ELIGIBILITY CHECK:\n";
    echo "--------------------------------\n";
    
    $isEligible = $transaction->validation_status === 'VALID' && 
                 $transaction->job_status === Transaction::JOB_STATUS_COMPLETED &&
                 !$transaction->webappForward()->where('status', \App\Models\WebappTransactionForward::STATUS_COMPLETED)->exists();
    
    echo "• Meets forwarding criteria: " . ($isEligible ? '✅ YES' : '❌ NO') . "\n";
    
    if ($isEligible) {
        echo "• This transaction should appear in next WebApp forwarding run\n";
        echo "• Expected behavior: Next cron should forward this transaction\n";
    } else {
        echo "• Check transaction creation logic\n";
    }
    
    echo "\n🎯 NEXT STEPS:\n";
    echo "==============\n";
    echo "1. Wait for next WebApp forwarding cron (runs every 5 minutes)\n";
    echo "2. Check logs for forwarding attempt\n";
    echo "3. Run: php scripts/staging-webapp-test.php\n";
    echo "4. Verify transaction gets forwarded\n";
    echo "5. Check webapp_transaction_forwards table for record\n\n";
    
    echo "🔍 MONITORING COMMANDS:\n";
    echo "=======================\n";
    echo "• Check transaction: SELECT * FROM transactions WHERE id = {$transaction->id};\n";
    echo "• Check forwards: SELECT * FROM webapp_transaction_forwards WHERE transaction_id = {$transaction->id};\n";
    echo "• Watch logs: tail -f storage/logs/laravel.log | grep 'WebApp forwarding'\n\n";
    
    echo "✅ Test transaction ready for WebApp forwarding verification!\n";
    
} catch (Exception $e) {
    DB::rollBack();
    echo "\n❌ Transaction creation failed: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    echo "\n🔧 TROUBLESHOOTING:\n";
    echo "   • Check database connectivity\n";
    echo "   • Verify POS terminals exist\n";
    echo "   • Review table schemas\n";
    echo "   • Check Laravel configuration\n";
}
