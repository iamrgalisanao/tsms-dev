<?php

namespace Tests\Traits;

use App\Models\User;
use App\Models\PosTerminal;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;

trait AuthTestHelpers
{
    /**
     * Set up auth testing environment with mocked cookie service
     */
    protected function setUpAuthTestEnvironment()
    {
        // Set up testing environment
        Config::set('app.env', 'testing');
        Config::set('app.debug', true);
        
        // Use a random key for testing
        Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        
        // Set up database and cache for testing
        Config::set('database.default', 'mysql');
        Config::set('cache.default', 'array');
        
        // Mock the cookie service that's missing from providers
        $this->mockCookieService();
        
        // Make sure roles are reset
        $this->resetRoles();
    }
    
    /**
     * Mock the cookie service to prevent the Target class [cookie] not found error
     */
    protected function mockCookieService()
    {
        // Create a mock cookie service that implements the QueueingFactory interface
        $cookieMock = Mockery::mock(\Illuminate\Contracts\Cookie\QueueingFactory::class);
        
        // Implement required methods with blank implementations
        $cookieMock->shouldReceive('make')->andReturn(null);
        $cookieMock->shouldReceive('forever')->andReturn(null);
        $cookieMock->shouldReceive('forget')->andReturn(null);
        $cookieMock->shouldReceive('queue')->andReturn(null);
        $cookieMock->shouldReceive('unqueue')->andReturn(null);
        $cookieMock->shouldReceive('hasQueued')->andReturn(false);
        $cookieMock->shouldReceive('getQueuedCookies')->andReturn([]);
        
        // Register the mock
        app()->instance('cookie', $cookieMock);
    }
    
    /**
     * Reset roles to ensure test isolation
     */
    protected function resetRoles()
    {
        // Create a test role if it doesn't exist
        Role::firstOrCreate(['name' => 'test_user', 'guard_name' => 'web']);
        
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
    
    /**
     * Create a test user
     */
    protected function createTestUser(array $attributes = [])
    {
        $defaultAttributes = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123')
        ];
        
        $user = User::create(array_merge($defaultAttributes, $attributes));
        $user->assignRole('test_user');
        
        return $user;
    }
    
    /**
     * Create a user and authenticate as them
     */
    protected function actAsAuthenticatedUser(array $attributes = [])
    {
        $user = $this->createTestUser($attributes);
        Sanctum::actingAs($user);
        
        return $user;
    }
    
    /**
     * Set up a test terminal
     */
    protected function setupTestTerminal()
    {
        $terminal = PosTerminal::factory()->create([
            'status' => 'active'
        ]);
        
        $token = $terminal->createToken(
            'test-terminal-' . $terminal->terminal_uid,
            ['transaction:create', 'heartbeat:send']
        )->plainTextToken;
        
        return [
            'terminal' => $terminal,
            'token' => $token
        ];
    }

    /**
     * Use terminal token for requests
     */
    protected function withTerminalToken($token)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ]);
    }
    
    /**
     * Clean up Mockery
     */
    protected function tearDownAuthTestEnvironment()
    {
        Mockery::close();
    }
}