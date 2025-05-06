<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\CircuitBreaker;

class TestCircuitBreaker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'circuit:test 
                            {--failures=5 : Number of failures to simulate}
                            {--service=test_service : Service name to test}
                            {--status=500 : Status code for failed responses}
                            {--delay=0 : Delay in milliseconds to simulate}
                            {--recovery-time=5 : Time in seconds to wait for recovery}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test circuit breaker by simulating failures';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = $this->option('service');
        $failures = $this->option('failures');
        $status = $this->option('status');
        $delay = $this->option('delay');
        $recoveryTime = $this->option('recovery-time');
        
        $baseUrl = 'http://127.0.0.1:8000'; // Use exact host and port where Laravel is running
        $endpoint = "{$baseUrl}/api/v1/test-circuit-breaker";
        
        $this->info("Testing circuit breaker for service: {$service}");
        $this->info("Initial state verification...");
        
        // Get initial circuit breaker state
        $circuitBreaker = CircuitBreaker::where('name', $service)->first();
        
        if (!$circuitBreaker) {
            $this->info("No circuit breaker found for service {$service}. Will be created on first request.");
        } else {
            $this->info("Initial circuit breaker state: {$circuitBreaker->status}");
            
            // Reset to CLOSED if necessary
            if ($circuitBreaker->status !== 'CLOSED') {
                $circuitBreaker->status = 'CLOSED';
                $circuitBreaker->trip_count = 0;
                $circuitBreaker->save();
                $this->info("Reset circuit breaker to CLOSED state");
            }
        }
        
        // Step 1: Verify service works normally
        $this->info("\nStep 1: Verifying service works normally...");
        $response = Http::withHeaders([
            'X-Tenant-ID' => '1' // Use tenant ID 1 for testing
        ])->get($endpoint);
        
        if ($response->successful()) {
            $this->info("✅ Service is working normally: " . $response->status());
        } else {
            $this->error("❌ Service is not working correctly: " . $response->status());
            return 1;
        }
        
        // Step 2: Cause circuit breaker to open with failures
        $this->info("\nStep 2: Causing circuit breaker to open with {$failures} failures...");
        
        $failureParams = "?fail=true&status={$status}";
        if ($delay > 0) {
            $failureParams .= "&delay={$delay}";
        }
        
        $progressBar = $this->output->createProgressBar($failures);
        $progressBar->start();
        
        for ($i = 0; $i < $failures; $i++) {
            try {
                $response = Http::withHeaders([
                    'X-Tenant-ID' => '1' // Use tenant ID 1 for testing
                ])->get($endpoint . $failureParams);
                $this->newLine();
                $this->line('  Failure ' . ($i + 1) . ': Status ' . $response->status());
            } catch (\Exception $e) {
                $this->newLine();
                $this->line("  Failure " . ($i + 1) . ": Exception: " . $e->getMessage());
            }
            $progressBar->advance();
            
            // Check if circuit is open after each failure
            $circuitBreaker = CircuitBreaker::where('name', $service)->first();
            if ($circuitBreaker && $circuitBreaker->status === 'OPEN') {
                $this->newLine();
                $this->info("✅ Circuit breaker opened after " . ($i + 1) . " failures!");
                break;
            }
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        // Step 3: Verify circuit is now open
        $this->info("Step 3: Verifying circuit is now open...");
        $circuitBreaker = CircuitBreaker::where('name', $service)->first();
        
        if (!$circuitBreaker) {
            $this->error("❌ Circuit breaker not found after failures!");
            return 1;
        }
        
        if ($circuitBreaker->status === 'OPEN') {
            $this->info("✅ Circuit breaker is open: {$circuitBreaker->status}");
        } else {
            $this->error("❌ Circuit breaker did not open! Current state: {$circuitBreaker->status}");
            return 1;
        }
        
        // Step 4: Verify service calls are rejected
        $this->info("\nStep 4: Verifying service calls are rejected when circuit is open...");
        $response = Http::withHeaders([
            'X-Tenant-ID' => '1' // Use tenant ID 1 for testing
        ])->get($endpoint);
        
        if ($response->status() === 503) {
            $this->info("✅ Service calls are correctly rejected with status 503");
        } else {
            $this->error("❌ Service calls are not being rejected! Got status: " . $response->status());
            return 1;
        }
        
        // Step 5: Wait for recovery and test half-open state
        $this->info("\nStep 5: Waiting {$recoveryTime} seconds for recovery to test half-open state...");
        $this->info("(This simulates the automatic recovery job that runs periodically)");
        
        // Manually update cooldown time to speed up test
        $circuitBreaker->cooldown_until = now()->subSecond();
        $circuitBreaker->save();
        
        sleep(1); // Just a small pause for better readability
        
        $this->info("Trying a test request in half-open state...");
        // Don't pass any failure parameters to ensure success
        $response = Http::withHeaders([
            'X-Tenant-ID' => '1' // Use tenant ID 1 for testing
        ])->get($endpoint . '?fail=false');
        
        if ($response->successful()) {
            $this->info("✅ Request succeeded in half-open state");
            
            // Verify circuit is now closed
            $circuitBreaker->refresh();
            if ($circuitBreaker->status === 'CLOSED') {
                $this->info("✅ Circuit breaker transitioned back to CLOSED state after successful request");
            } else {
                $this->warning("⚠️ Circuit breaker is still in {$circuitBreaker->status} state");
            }
        } else {
            $this->error("❌ Request in half-open state failed: " . $response->status());
        }
        
        $this->info("\n✅ Circuit breaker verification complete");
        return 0;
    }
}
