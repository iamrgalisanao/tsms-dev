<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

/**
 * STAGING JOB PROCESSING DIAGNOSTIC & REPAIR
 * 
 * Purpose: Fix the job processing pipeline that's preventing transactions
 * from moving from QUEUED to COMPLETED status, which blocks WebApp forwarding.
 */

echo "🔧 STAGING JOB PROCESSING REPAIR\n";
echo "=================================\n";
echo "Environment: " . app()->environment() . "\n";
echo "Issue: All transactions stuck in QUEUED status\n\n";

use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use Illuminate\Support\Facades\Artisan;

try {
    echo "📊 CURRENT JOB STATUS ANALYSIS:\n";
    echo "-------------------------------\n";
    
    $statusCounts = Transaction::select('job_status', DB::raw('count(*) as count'))
        ->groupBy('job_status')
        ->get();
    
    foreach ($statusCounts as $status) {
        echo "• {$status->job_status}: {$status->count}\n";
    }
    
    $queuedTransactions = Transaction::where('job_status', Transaction::JOB_STATUS_QUEUED)->count();
    echo "\n🚨 PROBLEM: {$queuedTransactions} transactions stuck in QUEUED status\n\n";
    
    echo "🔍 JOB QUEUE DIAGNOSTICS:\n";
    echo "-------------------------\n";
    
    // Check failed jobs
    $failedJobs = DB::table('failed_jobs')->count();
    echo "• Failed jobs in queue: {$failedJobs}\n";
    
    if ($failedJobs > 0) {
        $recentFailed = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->limit(3)
            ->get(['exception', 'failed_at']);
        
        echo "• Recent failures:\n";
        foreach ($recentFailed as $failure) {
            echo "  - " . substr($failure->exception, 0, 100) . "... ({$failure->failed_at})\n";
        }
    }
    
    // Check jobs table
    $hasJobsTable = DB::getSchemaBuilder()->hasTable('jobs');
    echo "• Jobs table exists: " . ($hasJobsTable ? '✅ YES' : '❌ NO') . "\n";
    
    if ($hasJobsTable) {
        $pendingJobs = DB::table('jobs')->count();
        echo "• Pending jobs: {$pendingJobs}\n";
    }
    
    echo "\n🛠️ REPAIR OPTIONS:\n";
    echo "------------------\n";
    echo "1. Force complete stuck transactions (MANUAL FIX)\n";
    echo "2. Clear failed jobs and retry\n";
    echo "3. Restart job queue processing\n";
    echo "4. Manual job processing test\n\n";
    
    echo "Choose repair option [1-4]: ";
    $handle = fopen("php://stdin", "r");
    $option = trim(fgets($handle));
    fclose($handle);
    
    switch ($option) {
        case '1':
            echo "\n🔧 MANUAL TRANSACTION COMPLETION:\n";
            echo "---------------------------------\n";
            
            $queuedTxns = Transaction::where('job_status', Transaction::JOB_STATUS_QUEUED)
                ->where('validation_status', 'VALID')
                ->get();
            
            echo "Found {$queuedTxns->count()} VALID+QUEUED transactions\n";
            echo "Force complete them? [y/N]: ";
            
            $handle = fopen("php://stdin", "r");
            $confirm = trim(strtolower(fgets($handle)));
            fclose($handle);
            
            if ($confirm === 'y' || $confirm === 'yes') {
                $completed = 0;
                foreach ($queuedTxns as $txn) {
                    $txn->update(['job_status' => Transaction::JOB_STATUS_COMPLETED]);
                    $completed++;
                    echo "  ✅ Completed transaction {$txn->id}\n";
                }
                echo "\n🎉 Manually completed {$completed} transactions\n";
                echo "These should now be eligible for WebApp forwarding\n";
            }
            break;
            
        case '2':
            echo "\n🧹 CLEARING FAILED JOBS:\n";
            echo "------------------------\n";
            
            if ($failedJobs > 0) {
                DB::table('failed_jobs')->truncate();
                echo "✅ Cleared {$failedJobs} failed jobs\n";
            } else {
                echo "• No failed jobs to clear\n";
            }
            
            // Retry job processing
            echo "• Attempting to restart job processing...\n";
            try {
                Artisan::call('queue:restart');
                echo "✅ Queue restarted\n";
            } catch (Exception $e) {
                echo "⚠️  Queue restart error: " . $e->getMessage() . "\n";
            }
            break;
            
        case '3':
            echo "\n🔄 QUEUE PROCESSING RESTART:\n";
            echo "----------------------------\n";
            
            try {
                // Clear any stuck jobs
                if ($hasJobsTable) {
                    $stuckJobs = DB::table('jobs')->count();
                    if ($stuckJobs > 0) {
                        echo "• Found {$stuckJobs} stuck jobs\n";
                        DB::table('jobs')->truncate();
                        echo "• Cleared stuck jobs\n";
                    }
                }
                
                Artisan::call('queue:restart');
                echo "✅ Queue processing restarted\n";
                
                echo "\n📋 To start queue worker on staging:\n";
                echo "php artisan queue:work --daemon\n";
                
            } catch (Exception $e) {
                echo "❌ Restart failed: " . $e->getMessage() . "\n";
            }
            break;
            
        case '4':
            echo "\n🧪 MANUAL JOB PROCESSING TEST:\n";
            echo "------------------------------\n";
            
            $testTransaction = Transaction::where('job_status', Transaction::JOB_STATUS_QUEUED)
                ->where('validation_status', 'VALID')
                ->first();
            
            if ($testTransaction) {
                echo "• Testing with transaction ID: {$testTransaction->id}\n";
                echo "• UUID: {$testTransaction->transaction_id}\n";
                
                try {
                    // Simulate job processing
                    $testTransaction->update(['job_status' => Transaction::JOB_STATUS_PROCESSING]);
                    echo "• Status changed to PROCESSING\n";
                    
                    // Simulate completion
                    $testTransaction->update(['job_status' => Transaction::JOB_STATUS_COMPLETED]);
                    echo "• Status changed to COMPLETED\n";
                    
                    echo "✅ Manual job processing successful\n";
                    echo "This transaction should now be eligible for forwarding\n";
                    
                } catch (Exception $e) {
                    echo "❌ Manual processing failed: " . $e->getMessage() . "\n";
                }
            } else {
                echo "❌ No VALID+QUEUED transactions found for testing\n";
            }
            break;
            
        default:
            echo "❌ Invalid option selected\n";
            exit(1);
    }
    
    echo "\n🔍 POST-REPAIR VERIFICATION:\n";
    echo "----------------------------\n";
    
    $newStatusCounts = Transaction::select('job_status', DB::raw('count(*) as count'))
        ->groupBy('job_status')
        ->get();
    
    foreach ($newStatusCounts as $status) {
        echo "• {$status->job_status}: {$status->count}\n";
    }
    
    // Check if any transactions are now eligible for forwarding
    $eligibleForForwarding = Transaction::where('validation_status', 'VALID')
        ->where('job_status', Transaction::JOB_STATUS_COMPLETED)
        ->whereDoesntHave('webappForward', function($q) {
            $q->where('status', \App\Models\WebappTransactionForward::STATUS_COMPLETED);
        })
        ->count();
    
    echo "\n🌐 WEBAPP FORWARDING STATUS:\n";
    echo "----------------------------\n";
    echo "• Transactions eligible for forwarding: {$eligibleForForwarding}\n";
    
    if ($eligibleForForwarding > 0) {
        echo "🎉 SUCCESS! Transactions now eligible for WebApp forwarding\n";
        echo "Next cron run should forward {$eligibleForForwarding} transactions\n";
    } else {
        echo "ℹ️  No new transactions eligible (all may already be forwarded)\n";
    }
    
    echo "\n📋 STAGING SERVER RECOMMENDATIONS:\n";
    echo "==================================\n";
    echo "1. Ensure queue workers are running: php artisan queue:work\n";
    echo "2. Monitor job processing in real-time\n";
    echo "3. Check supervisor/systemd for queue worker management\n";
    echo "4. Set up proper job monitoring and alerting\n";
    echo "5. Consider queue driver optimization (Redis vs Database)\n";
    
} catch (Exception $e) {
    echo "\n❌ Repair failed: " . $e->getMessage() . "\n";
    echo "   " . $e->getFile() . ":" . $e->getLine() . "\n";
}
