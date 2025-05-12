<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\Tenant;

trait CircuitBreakerTestHelpers
{
    /**
     * Set up a clean test environment for circuit breaker tests
     */
    protected function setUpCircuitBreakerTest()
    {
        // Set up testing environment
        config(['app.env' => 'testing']);
        config(['app.debug' => true]);
        
        // Use a random key for testing
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        
        // Set up database for testing
        config(['database.default' => 'mysql']);
        
        // Configure Redis to use a separate testing database
        config(['redis.connections.default.database' => '15']); // Use DB 15 for testing
        
        // Set cache to array driver for testing
        config(['cache.default' => 'array']);
        
        // Configure circuit breaker settings
        config(['circuit_breaker.threshold' => 3]);
        config(['circuit_breaker.cooldown' => 60]);
        
        try {
            // Clean Redis test database
            Redis::connection()->flushdb();
            
            // Run migrations
            Artisan::call('migrate:fresh', ['--force' => true]);
            
            // Create test tenants
            $this->createTestTenants();
        } catch (\Exception $e) {
            Log::error('Circuit breaker test setup failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Set up test tenants for circuit breaker tests
     */
    protected function createTestTenants()
    {
        // Create first test tenant
        Tenant::firstOrCreate(
            ['code' => 'TEST1'],
            [
                'name' => 'Test Tenant 1',
                'code' => 'TEST1'
            ]
        );
        
        // Create second test tenant
        Tenant::firstOrCreate(
            ['code' => 'TEST2'],
            [
                'name' => 'Test Tenant 2',
                'code' => 'TEST2'
            ]
        );
    }
    
    /**
     * Clean up after circuit breaker tests
     */
    protected function tearDownCircuitBreakerTest()
    {
        // Clean Redis test database
        Redis::connection()->flushdb();
    }
}
