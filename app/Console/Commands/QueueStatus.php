<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueStatus extends Command
{
    protected $signature = 'queue:status';
    protected $description = 'Show queue status and pending jobs';

    public function handle()
    {
        while(true) {
            $this->line("\nQueue Status at " . now()->format('Y-m-d H:i:s'));
            $this->line("----------------------------------------");
            
            // Get pending jobs
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();
            
            // Get jobs by status
            $processing = DB::table('transactions')
                ->where('job_status', 'PROCESSING')
                ->count();
            
            $queued = DB::table('transactions')
                ->where('job_status', 'QUEUED')
                ->count();
                
            $failed_trans = DB::table('transactions')
                ->where('job_status', 'FAILED')
                ->count();

            $this->info("Pending Jobs: " . $pending);
            $this->error("Failed Jobs: " . $failed);
            $this->line("Processing: " . $processing);
            $this->line("Queued: " . $queued);
            $this->error("Failed Transactions: " . $failed_trans);

            sleep(5); // Wait 5 seconds
            $this->output->write("\033[H\033[2J"); // Clear screen
        }
    }
}