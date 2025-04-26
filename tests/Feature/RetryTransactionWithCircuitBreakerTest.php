<?php


namespace Tests\Feature;

use App\Jobs\RetryTransactionJob;
use App\Models\CircuitBreaker;
use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryTransactionWithCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function retry_job_respects_open_circuit()
    {
        Queue::fake();
        
        // Create a tenant manually without using the model's save method
        $tenantId = 1;
        \DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Test Tenant',
            'code' => 'TEST',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $tenant = Tenant::find($tenantId);
        
        // Create a terminal directly in the database
        $terminalId = 1;
        \DB::table('pos_terminals')->insert([
            'id' => $terminalId,
            'tenant_id' => $tenantId,
            'terminal_uid' => 'TERM1',
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $terminal = PosTerminal::find($terminalId);
        $terminal->retry_enabled = true; // Add this property for the test
        
        // Create a token for the terminal to prevent TOKEN_MISSING error
        \DB::table('terminal_tokens')->insert([
            'terminal_id' => $terminalId,
            'access_token' => 'test-token-123',
            'issued_at' => now(),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Create a failed integration log
        $logId = 1;
        \DB::table('integration_logs')->insert([
            'id' => $logId,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'status' => 'FAILED',
            'retry_count' => 0,
            'next_retry_at' => now()->subMinute(),
            'request_payload' => json_encode(['test' => 'payload']),
            'response_payload' => json_encode(['error' => 'test error']),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $log = IntegrationLog::find($logId);
        
        // Create and trip a circuit breaker
        $circuitBreaker = CircuitBreaker::forService('api.transactions', $tenant->id);
        $circuitBreaker->state = CircuitBreaker::STATE_OPEN;
        $circuitBreaker->cooldown_until = Carbon::now()->addMinutes(5);
        $circuitBreaker->save();
        
        // Dispatch the retry job
        RetryTransactionJob::dispatch($log);
        
        // Check that job gets run
        Queue::assertPushed(RetryTransactionJob::class);
        
        // When the job is actually processed, it should detect the open circuit
        // Let's call the job handle method manually
        $job = new RetryTransactionJob($log);
        $job->handle();
        
        // Refresh log from database to check updates
        $log->refresh();
        
        // Log should be updated with circuit breaker info
        $this->assertEquals('CIRCUIT_BREAKER_OPEN', $log->retry_reason);
        $this->assertNotNull($log->next_retry_at);
        
        // Compare with current time since next_retry_at may be stored as a string
        $this->assertTrue(
            strtotime($log->next_retry_at) > time(), 
            "The next retry time should be in the future"
        );
    }
}