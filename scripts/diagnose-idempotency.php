<?php

/**
 * TSMS Idempotency Diagnostic Tool
 * 
 * This script diagnoses idempotency issues by examining submission and transaction records.
 * Usage: php scripts/diagnose-idempotency.php <submission_uuid>
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\TransactionSubmission;
use App\Models\Transaction;

class IdempotencyDiagnostic
{
    public function diagnose(string $submissionUuid): void
    {
        echo "=== TSMS Idempotency Diagnostic ===\n";
        echo "Submission UUID: {$submissionUuid}\n\n";
        
        // Check TransactionSubmission records
        $submissions = TransactionSubmission::where('submission_uuid', $submissionUuid)->get();
        echo "📋 TransactionSubmission Records: " . $submissions->count() . "\n";
        
        if ($submissions->count() > 0) {
            foreach ($submissions as $i => $sub) {
                echo "  [{$i}] ID: {$sub->id}\n";
                echo "      Status: {$sub->status}\n";
                echo "      Terminal ID: {$sub->terminal_id}\n";
                echo "      Transaction Count: {$sub->transaction_count}\n";
                echo "      Payload Checksum: " . substr($sub->payload_checksum, 0, 16) . "...\n";
                echo "      Created: {$sub->created_at}\n";
                echo "      Updated: {$sub->updated_at}\n\n";
            }
        } else {
            echo "  ✅ No TransactionSubmission records found\n\n";
        }
        
        // Check Transaction records
        $transactions = Transaction::where('submission_uuid', $submissionUuid)->get();
        echo "💳 Transaction Records: " . $transactions->count() . "\n";
        
        if ($transactions->count() > 0) {
            foreach ($transactions as $i => $txn) {
                echo "  [{$i}] ID: {$txn->id}\n";
                echo "      Transaction ID: {$txn->transaction_id}\n";
                echo "      Terminal ID: {$txn->terminal_id}\n";
                echo "      Validation Status: {$txn->validation_status}\n";
                echo "      Base Amount: {$txn->base_amount}\n";
                echo "      Voided: " . ($txn->voided_at ? 'YES' : 'NO') . "\n";
                echo "      Created: {$txn->created_at}\n\n";
            }
        } else {
            echo "  ✅ No Transaction records found\n\n";
        }
        
        // Analyze the idempotency issue
        $this->analyzeIdempotencyIssue($submissions, $transactions);
    }
    
    private function analyzeIdempotencyIssue($submissions, $transactions): void
    {
        echo "🔍 ISSUE ANALYSIS:\n";
        
        if ($submissions->count() > 0 && $transactions->count() === 0) {
            echo "❌ CRITICAL: Ghost submission records detected!\n";
            echo "   - TransactionSubmission records exist but no actual Transaction records\n";
            echo "   - This indicates submissions were created before validation failed\n";
            echo "   - Root cause: TransactionSubmission created before checksum validation\n";
            echo "   - Impact: False idempotency triggers for corrected submissions\n\n";
            
            echo "💡 RECOMMENDED ACTION:\n";
            echo "   1. Delete ghost TransactionSubmission records\n";
            echo "   2. Deploy fixed controller code (validation before submission creation)\n";
            echo "   3. Verify staging deployment has correct code\n\n";
            
            $this->showCleanupCommands($submissions);
            
        } elseif ($submissions->count() > 0 && $transactions->count() > 0) {
            echo "✅ NORMAL: Complete submission with transaction records\n";
            echo "   - This indicates successful processing\n";
            echo "   - Idempotency behavior is expected and correct\n\n";
            
        } elseif ($submissions->count() === 0 && $transactions->count() === 0) {
            echo "✅ CLEAN: No records found\n";
            echo "   - This submission UUID is clean\n";
            echo "   - Ready for new submission\n\n";
            
        } else {
            echo "⚠️  UNUSUAL: Transaction records without submission envelope\n";
            echo "   - This should not happen in normal operation\n";
            echo "   - May indicate data inconsistency\n\n";
        }
    }
    
    private function showCleanupCommands($submissions): void
    {
        echo "🧹 CLEANUP COMMANDS:\n";
        foreach ($submissions as $sub) {
            echo "   php artisan tinker --execute=\"App\\Models\\TransactionSubmission::find({$sub->id})->delete(); echo 'Deleted ghost submission {$sub->id}';\"" . "\n";
        }
        echo "\n";
    }
}

// CLI execution
if (basename($_SERVER['PHP_SELF']) === 'diagnose-idempotency.php') {
    $submissionUuid = $argv[1] ?? null;
    
    if (!$submissionUuid) {
        echo "Usage: php scripts/diagnose-idempotency.php <submission_uuid>\n";
        echo "Example: php scripts/diagnose-idempotency.php 807b61f5-1a49-42c1-9e42-dd0d197b4207\n";
        exit(1);
    }
    
    $diagnostic = new IdempotencyDiagnostic();
    $diagnostic->diagnose($submissionUuid);
}
