<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use App\Models\CircuitBreaker;

class MonitorCircuitBreaker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'circuit:monitor
                            {--service=test_service : Service name to monitor}
                            {--interval=2 : Refresh interval in seconds}
                            {--duration=60 : Total monitoring duration in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor circuit breaker metrics in real-time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = $this->option('service');
        $interval = (int)$this->option('interval');
        $duration = (int)$this->option('duration');
        $iterations = floor($duration / $interval);
        
        $this->info("Starting circuit breaker monitoring for service: {$service}");
        $this->info("Press Ctrl+C to stop monitoring");
        $this->newLine();
        
        // Display header
        $this->displayHeader();
        
        // Monitor loop
        for ($i = 0; $i < $iterations; $i++) {
            // Clear the previous line
            if ($i > 0) {
                $this->output->write("\x1B[1A\x1B[2K");
            }
            
            // Display current metrics
            $this->displayMetrics($service);
            
            // Wait for next update
            if ($i < $iterations - 1) {
                sleep($interval);
            }
        }
        
        $this->newLine();
        $this->info("Monitoring complete. Press any key to exit.");
        return 0;
    }

    /**
     * Display the header row
     */
    private function displayHeader()
    {
        $this->line(sprintf(
            "%-15s %-10s %-12s %-15s %-15s %-20s",
            "Time",
            "Status",
            "Trip Count",
            "Success Count",
            "Failure Count", 
            "Last Failure"
        ));
        
        $this->line(str_repeat('-', 100));
    }

    /**
     * Display current metrics for a service
     */
    private function displayMetrics(string $service)
    {
        // Get circuit breaker from database
        $circuitBreaker = CircuitBreaker::where('name', $service)->first();
        
        // Get metrics from Redis
        $successCount = (int)Redis::get("circuit_breaker:{$service}:success_count") ?? 0;
        $failureCount = (int)Redis::get("circuit_breaker:{$service}:failure_count") ?? 0;
        $lastFailure = Redis::get("circuit_breaker:{$service}:last_failure") ?? 'Never';
        
        if ($lastFailure !== 'Never') {
            $lastFailure = date('H:i:s', (int)$lastFailure);
        }
        
        // Format status with color
        $status = $circuitBreaker ? $circuitBreaker->status : 'UNKNOWN';
        $statusFormatted = $status;
        
        if ($status === 'CLOSED') {
            $statusFormatted = "<fg=green>{$status}</>";
        } elseif ($status === 'OPEN') {
            $statusFormatted = "<fg=red>{$status}</>";
        } elseif ($status === 'HALF-OPEN') {
            $statusFormatted = "<fg=yellow>{$status}</>";
        }
        
        // Display current time and metrics
        $this->line(sprintf(
            "%-15s %-10s %-12s %-15s %-15s %-20s",
            date('H:i:s'),
            $statusFormatted,
            $circuitBreaker ? $circuitBreaker->trip_count : 'N/A',
            $successCount,
            $failureCount,
            $lastFailure
        ));
    }
}
