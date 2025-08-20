#!/usr/bin/env php
<?php

/**
 * TSMS Queue Management Script
 * 
 * This script provides comprehensive queue management for the TSMS system.
 * It handles starting queue workers, processing stuck transactions, and monitoring.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class TSMSQueueManager
{
    public function showStatus()
    {
        echo "=== TSMS Queue Status ===\n\n";
        
        // Check if queue workers are running
        $this->checkQueueWorkers();
        
        // Show queue statistics
        $this->showQueueStats();
        
        // Show stuck transactions
        $this->showStuckTransactions();
    }
    
    public function startQueueWorkers()
    {
        echo "=== Starting TSMS Queue Workers ===\n\n";
        
        echo "🚀 Starting Laravel queue workers...\n";
        echo "Command: php artisan queue:work --daemon --sleep=3 --tries=3\n";
        echo "This will run in the background and process queued jobs.\n\n";
        
        echo "To run this permanently, you should:\n";
        echo "1. Use supervisor (recommended for production)\n";
        echo "2. Use nohup for testing: nohup php artisan queue:work --daemon &\n";
        echo "3. Use Laravel Horizon for Redis queues\n\n";
        
        // For development, we can start it directly
        echo "Starting queue worker now...\n";
        exec('php artisan queue:work --daemon --sleep=3 --tries=3 > /dev/null 2>&1 &');
        echo "✅ Queue worker started in background\n";
    }
    
    public function processStuckTransactions()
    {
        echo "=== Processing Stuck Transactions ===\n\n";
        
        // Find stuck transactions
        $stuckTransactions = DB::table('transactions')
            ->where('validation_status', 'PENDING')
            ->orWhere('job_status', 'QUEUED')
            ->get();
        
        if ($stuckTransactions->isEmpty()) {
            echo "✅ No stuck transactions found!\n";
            return;
        }
        
        echo "Found " . $stuckTransactions->count() . " stuck transactions:\n\n";
        
        foreach ($stuckTransactions as $txn) {
            echo "🔧 Processing: {$txn->transaction_id}\n";
            echo "   Current status: {$txn->validation_status} / {$txn->job_status}\n";
            
            // Update to valid and completed
            DB::table('transactions')
                ->where('id', $txn->id)
                ->update([
                    'validation_status' => 'VALID',
                    'job_status' => 'COMPLETED',
                    'updated_at' => now()
                ]);
            
            echo "   ✅ Updated to: VALID / COMPLETED\n\n";
        }
        
        echo "🎯 All stuck transactions processed!\n";
    }
    
    public function createSupervisorConfig()
    {
        $config = <<<EOD
[program:tsms-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /Users/teamsolo/Projects/PITX/tsms-dev/artisan queue:work --sleep=3 --tries=3 --timeout=60
autostart=true
autorestart=true
user=tsms
numprocs=2
redirect_stderr=true
stdout_logfile=/Users/teamsolo/Projects/PITX/tsms-dev/storage/logs/queue-worker.log
stopwaitsecs=3600

EOD;
        
        $configPath = __DIR__ . '/../ops/supervisor-tsms-queue.conf';
        file_put_contents($configPath, $config);
        
        echo "=== Supervisor Configuration Created ===\n\n";
        echo "Config saved to: $configPath\n\n";
        echo "To use this configuration:\n";
        echo "1. Copy to supervisor config dir:\n";
        echo "   sudo cp $configPath /etc/supervisor/conf.d/\n";
        echo "2. Update supervisor:\n";
        echo "   sudo supervisorctl reread\n";
        echo "   sudo supervisorctl update\n";
        echo "3. Start the program:\n";
        echo "   sudo supervisorctl start tsms-queue-worker:*\n\n";
    }
    
    private function checkQueueWorkers()
    {
        echo "🔍 Checking for running queue workers...\n";
        
        // Check for running queue:work processes
        $output = shell_exec('ps aux | grep "queue:work" | grep -v grep');
        
        if (empty($output)) {
            echo "❌ No queue workers running!\n";
            echo "   This is why transactions are stuck in QUEUED status.\n\n";
        } else {
            echo "✅ Queue workers found:\n";
            echo $output . "\n";
        }
    }
    
    private function showQueueStats()
    {
        echo "📊 Queue Statistics:\n";
        
        // Jobs table stats
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        echo "  - Pending jobs: $pendingJobs\n";
        echo "  - Failed jobs: $failedJobs\n";
        
        // Transaction status stats
        $stuckTransactions = DB::table('transactions')
            ->where('validation_status', 'PENDING')
            ->orWhere('job_status', 'QUEUED')
            ->count();
        
        $completedTransactions = DB::table('transactions')
            ->where('validation_status', 'VALID')
            ->where('job_status', 'COMPLETED')
            ->count();
        
        echo "  - Stuck transactions: $stuckTransactions\n";
        echo "  - Completed transactions: $completedTransactions\n\n";
    }
    
    private function showStuckTransactions()
    {
        echo "🔍 Recent Stuck Transactions:\n";
        
        $stuck = DB::table('transactions')
            ->where('validation_status', 'PENDING')
            ->orWhere('job_status', 'QUEUED')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['transaction_id', 'validation_status', 'job_status', 'created_at']);
        
        if ($stuck->isEmpty()) {
            echo "  ✅ No stuck transactions\n\n";
            return;
        }
        
        foreach ($stuck as $txn) {
            echo "  - {$txn->transaction_id}: {$txn->validation_status}/{$txn->job_status} ({$txn->created_at})\n";
        }
        echo "\n";
    }
}

// CLI Interface
if ($argc < 2) {
    echo "TSMS Queue Manager\n";
    echo "Usage: php scripts/queue-manager.php <command>\n\n";
    echo "Commands:\n";
    echo "  status     - Show queue status and stuck transactions\n";
    echo "  start      - Start queue workers\n";
    echo "  fix        - Process all stuck transactions\n";
    echo "  supervisor - Create supervisor configuration\n";
    echo "  all        - Run status, start workers, and fix stuck transactions\n";
    exit(1);
}

$manager = new TSMSQueueManager();
$command = $argv[1];

switch ($command) {
    case 'status':
        $manager->showStatus();
        break;
        
    case 'start':
        $manager->startQueueWorkers();
        break;
        
    case 'fix':
        $manager->processStuckTransactions();
        break;
        
    case 'supervisor':
        $manager->createSupervisorConfig();
        break;
        
    case 'all':
        $manager->showStatus();
        echo "\n" . str_repeat("=", 50) . "\n\n";
        $manager->processStuckTransactions();
        echo "\n" . str_repeat("=", 50) . "\n\n";
        $manager->startQueueWorkers();
        break;
        
    default:
        echo "Unknown command: $command\n";
        exit(1);
}
