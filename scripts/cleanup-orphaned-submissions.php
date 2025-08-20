<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING CLEANUP SCRIPT
 * 
 * Purpose: Remove orphaned TransactionSubmission records that don't have 
 * corresponding Transaction records. This occurs when the dual-idempotency 
 * logic creates submissions but then returns early due to existing transactions.
 * 
 * USE CASE: Staging server deployment consistency after idempotency bug fix
 */

echo "🧹 STAGING CLEANUP: Orphaned TransactionSubmission Records\n";
echo "=========================================================\n\n";

try {
    DB::beginTransaction();
    
    // Find orphaned submissions (have submission but no transactions)
    $orphanedSubmissions = DB::table('transaction_submissions as ts')
        ->leftJoin('transactions as t', function($join) {
            $join->on('ts.submission_uuid', '=', 't.submission_uuid')
                 ->on('ts.terminal_id', '=', 't.terminal_id');
        })
        ->whereNull('t.submission_uuid')
        ->select('ts.*')
        ->get();
    
    echo "📊 Analysis Results:\n";
    echo "   • Total TransactionSubmissions: " . DB::table('transaction_submissions')->count() . "\n";
    echo "   • Orphaned Submissions Found: " . $orphanedSubmissions->count() . "\n\n";
    
    if ($orphanedSubmissions->count() === 0) {
        echo "✅ No orphaned records found. Database is clean!\n";
        DB::rollback();
        exit(0);
    }
    
    // Display orphaned records for review
    echo "🔍 Orphaned Submissions Detail:\n";
    echo "--------------------------------\n";
    foreach ($orphanedSubmissions as $submission) {
        echo "   • ID: {$submission->id}\n";
        echo "     Terminal: {$submission->terminal_id}\n";
        echo "     UUID: {$submission->submission_uuid}\n";
        echo "     Status: {$submission->status}\n";
        echo "     Created: {$submission->created_at}\n";
        echo "     Checksum: " . substr($submission->payload_checksum, 0, 16) . "...\n\n";
    }
    
    // Confirmation prompt
    echo "⚠️  WARNING: This will permanently delete {$orphanedSubmissions->count()} orphaned submission records.\n";
    echo "   These are submissions created by the dual-idempotency bug that have no\n";
    echo "   corresponding transaction records and represent incomplete processing.\n\n";
    
    echo "🤔 Do you want to proceed with cleanup? [y/N]: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    $confirm = trim(strtolower($line));
    if ($confirm !== 'y' && $confirm !== 'yes') {
        echo "\n❌ Cleanup cancelled by user.\n";
        DB::rollback();
        exit(0);
    }
    
    // Perform cleanup
    echo "\n🧹 Cleaning up orphaned records...\n";
    
    $deletedCount = 0;
    foreach ($orphanedSubmissions as $submission) {
        $deleted = DB::table('transaction_submissions')
            ->where('id', $submission->id)
            ->delete();
        
        if ($deleted) {
            $deletedCount++;
            echo "   ✓ Deleted submission ID {$submission->id} (UUID: {$submission->submission_uuid})\n";
        }
    }
    
    DB::commit();
    
    echo "\n✅ Cleanup Complete!\n";
    echo "   • Records Deleted: {$deletedCount}\n";
    echo "   • Database Status: Clean\n";
    echo "   • Next Steps: Deploy fixed TransactionController to prevent future orphans\n\n";
    
    // Log the cleanup for audit trail
    Log::info('Orphaned TransactionSubmission cleanup completed', [
        'total_orphaned' => $orphanedSubmissions->count(),
        'deleted_count' => $deletedCount,
        'cleanup_timestamp' => now(),
        'environment' => app()->environment(),
    ]);
    
} catch (Exception $e) {
    DB::rollback();
    echo "\n❌ Error during cleanup: " . $e->getMessage() . "\n";
    echo "   Stack trace logged to Laravel logs.\n";
    
    Log::error('Orphaned TransactionSubmission cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    
    exit(1);
}
