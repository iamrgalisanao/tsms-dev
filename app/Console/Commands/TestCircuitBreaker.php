<?php


namespace App\Console\Commands;

use App\Models\CircuitBreaker;
use Illuminate\Console\Command;
use Carbon\Carbon;

class TestCircuitBreaker extends Command
{
    protected $signature = 'circuit-breaker:test {service=api.transactions} {tenant_id=1} {--trip : Trip the circuit}';
    protected $description = 'Test the circuit breaker implementation';

    public function handle()
    {
        $service = $this->argument('service');
        $tenantId = $this->argument('tenant_id');
        
        $circuitBreaker = CircuitBreaker::forService($service, $tenantId);
        
        // Display current state
        $this->info("Circuit Breaker for '$service' (tenant: $tenantId)");
        $this->table(['State', 'Failures', 'Threshold', 'Cooldown Until'], [
            [
                $circuitBreaker->state, 
                $circuitBreaker->failure_count, 
                $circuitBreaker->failure_threshold,
                $circuitBreaker->cooldown_until ? $circuitBreaker->cooldown_until->toDateTimeString() : 'N/A'
            ]
        ]);
        
        if ($this->option('trip')) {
            $this->info("Tripping the circuit...");
            
            // Record enough failures to trip the circuit
            for ($i = 0; $i < $circuitBreaker->failure_threshold; $i++) {
                $circuitBreaker->recordFailure();
                $this->line(" - Recorded failure " . ($i + 1) . " of {$circuitBreaker->failure_threshold}");
            }
            
            // Refresh from database
            $circuitBreaker = CircuitBreaker::find($circuitBreaker->id);
            
            $this->info("Circuit is now: " . $circuitBreaker->state);
            $this->info("Cooldown until: " . $circuitBreaker->cooldown_until->toDateTimeString());
            
            $this->newLine();
            $this->info("Requests allowed? " . ($circuitBreaker->isAllowed() ? 'YES' : 'NO'));
        }
    }
}