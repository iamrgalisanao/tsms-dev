<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

trait NoAuthTestHelpers
{
    /**
     * Set up a clean database environment for testing
     */
    protected function setUpTestDatabase()
    {
        // Set up testing environment
        config(['app.env' => 'testing']);
        config(['app.debug' => true]);
        
        // Use a random key for testing
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        
        // Set up database and cache
        config(['database.default' => 'mysql']);
        config(['cache.default' => 'array']);
        
        try {
            // Run migrations
            Artisan::call('migrate:fresh', ['--force' => true]);
        } catch (\Exception $e) {
            Log::error('Migration failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
