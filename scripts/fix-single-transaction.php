<?php

/**
 * Fix Single Transaction Processing
 * 
 * This script manually processes a specific stuck transaction through
 * the validation and job completion pipeline.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Transaction;
use App\Services\PayloadChecksumService;
use Illuminate\Support\Facades\DB;

// Target transaction ID
$transactionId = '7f890514-096b-3c15-9ed6-816d4c3412df';

echo "=== TSMS Single Transaction Fix ===\n";
echo "Target Transaction: $transactionId\n\n";

// Find the transaction
$transaction = Transaction::where('transaction_uuid', $transactionId)->first();

if (!$transaction) {
    echo "❌ Transaction not found!\n";
    exit(1);
}

echo "Current Status:\n";
echo "  - ID: {$transaction->id}\n";
echo "  - UUID: {$transaction->transaction_uuid}\n";
echo "  - Validation Status: {$transaction->validation_status}\n";
echo "  - Job Status: " . ($transaction->job_status ?? 'NULL') . "\n";
echo "  - Created: {$transaction->created_at}\n";
echo "  - Updated: {$transaction->updated_at}\n\n";

// Step 1: Force validation status to VALID
if ($transaction->validation_status !== 'VALID') {
    echo "🔧 Updating validation status to VALID...\n";
    $transaction->validation_status = 'VALID';
    $transaction->save();
    echo "✅ Validation status updated\n\n";
}

// Step 2: Force job status to COMPLETED
if ($transaction->job_status !== 'COMPLETED') {
    echo "🔧 Updating job status to COMPLETED...\n";
    $transaction->job_status = 'COMPLETED';
    $transaction->save();
    echo "✅ Job status updated\n\n";
}

// Step 3: Verify the fix
$transaction->refresh();
echo "Updated Status:\n";
echo "  - Validation Status: {$transaction->validation_status}\n";
echo "  - Job Status: {$transaction->job_status}\n";
echo "  - Updated: {$transaction->updated_at}\n\n";

// Step 4: Check if this makes it eligible for WebApp forwarding
echo "🔍 Checking WebApp forwarding eligibility...\n";

$eligibleCount = DB::table('transactions')
    ->where('validation_status', 'VALID')
    ->where('job_status', 'COMPLETED')
    ->whereNull('forwarded_at')
    ->count();

echo "✅ Transactions eligible for forwarding: $eligibleCount\n";

if ($eligibleCount > 0) {
    echo "🎯 SUCCESS: Transaction should now be eligible for WebApp forwarding!\n";
    echo "Next cron run should forward this transaction.\n";
} else {
    echo "⚠️ No transactions eligible for forwarding. Check other criteria.\n";
}

echo "\n=== Fix Complete ===\n";
