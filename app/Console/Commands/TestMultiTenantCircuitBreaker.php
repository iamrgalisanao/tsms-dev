<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\Tenant;
use App\Models\CircuitBreaker;

class TestMultiTenantCircuitBreaker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'circuit:test-multi-tenant
                            {--tenants=2 : Number of tenants to test}
                            {--failures=5 : Number of failures to simulate}
                            {--service=test_service : Service name to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test circuit breaker multi-tenant isolation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantCount = (int)$this->option('tenants');
        $failures = (int)$this->option('failures');
        $service = $this->option('service');
        
        $this->info("Testing multi-tenant isolation with {$tenantCount} tenants");
        
        // Create or get test tenants
        $tenants = $this->getTestTenants($tenantCount);
        
        // Reset circuit breakers for clean testing
        foreach ($tenants as $tenant) {
            CircuitBreaker::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $service],
                ['status' => 'CLOSED', 'trip_count' => 0]
            );
        }
        
        $this->info("Set up {$tenantCount} tenants with CLOSED circuit breakers");
        
        // Trip circuit breaker for first tenant only
        $this->info("\nTripping circuit breaker for tenant: {$tenants[0]->name}");
        
        $baseUrl = env('APP_URL', 'http://localhost');
        $endpoint = "{$baseUrl}/api/v1/test-circuit-breaker";
        
        // Simulate failures for the first tenant
        for ($i = 0; $i < $failures; $i++) {
            try {
                $response = Http::withHeaders([
                    'X-Tenant-ID' => $tenants[0]->id
                ])->get($endpoint . '?fail=true');
                
                $this->line('  Failure ' . ($i + 1) . ': Status ' . $response->status());
            } catch (\Exception $e) {
                $this->line("  Failure " . ($i + 1) . ": Exception: " . $e->getMessage());
            }
        }
        
        // Check all circuit breakers to verify isolation
        $this->info("\nVerifying circuit breaker states across tenants:");
        $this->newLine();
        
        // Display header
        $this->line(sprintf("%-30s %-15s %-15s", "Tenant", "Circuit State", "Trip Count"));
        $this->line(str_repeat('-', 60));
        
        // Show circuit breaker state for each tenant
        foreach ($tenants as $index => $tenant) {
            $circuitBreaker = CircuitBreaker::where([
                'tenant_id' => $tenant->id,
                'name' => $service
            ])->first();
            
            if (!$circuitBreaker) {
                $this->line(sprintf("%-30s %-15s %-15s", 
                    $tenant->trade_name, 'NOT FOUND', 'N/A'));
                continue;
            }
            
            // Format status with color
            $status = $circuitBreaker->status;
            $statusFormatted = $status;
            
            if ($status === 'CLOSED') {
                $statusFormatted = "<fg=green>{$status}</>";
            } elseif ($status === 'OPEN') {
                $statusFormatted = "<fg=red>{$status}</>";
            }
            
            $isIsolated = ($index === 0 && $status === 'OPEN') || 
                         ($index > 0 && $status === 'CLOSED');
            
            // Display status
            $this->line(sprintf("%-30s %-15s %-15s %s", 
                $tenant->trade_name, 
                $statusFormatted, 
                $circuitBreaker->trip_count,
                $isIsolated ? '✅' : '❌'
            ));
        }
        
        // Determine if the test passed
        $firstCB = CircuitBreaker::where([
            'tenant_id' => $tenants[0]->id,
            'name' => $service
        ])->first();
        
        $otherCBs = CircuitBreaker::where('name', $service)
            ->where('tenant_id', '!=', $tenants[0]->id)
            ->get();
        
        $isolationSuccess = $firstCB && $firstCB->status === 'OPEN' && 
            $otherCBs->every(function($cb) {
                return $cb->status === 'CLOSED';
            });
        
        $this->newLine();
        if ($isolationSuccess) {
            $this->info("✅ Multi-tenant isolation verified: Circuit breakers are correctly isolated by tenant");
        } else {
            $this->error("❌ Multi-tenant isolation failed: Circuit breakers are not properly isolated");
        }
        
        return $isolationSuccess ? 0 : 1;
    }
    
    /**
     * Get or create test tenants
     */
    private function getTestTenants(int $count)
    {
        $tenants = [];
        
        for ($i = 0; $i < $count; $i++) {
            $tenant = Tenant::firstOrCreate(
                ['code' => "TEST_TENANT_{$i}"],
                ['name' => "Test Tenant {$i}"]
            );
            
            $tenants[] = $tenant;
        }
        
        return $tenants;
    }
}
