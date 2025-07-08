<?php

namespace Tests\Feature\TransactionPipeline;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Jobs\ProcessTransactionJob;
use App\Services\TransactionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TransactionQueueProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $transaction;
    protected $validationService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->tenant = Tenant::factory()->create([
            'customer_code' => 'TEST001',
            'status' => 'active'
        ]);
        
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status_id' => 1
        ]);
        
        $this->transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        $this->validationService = app(TransactionValidationService::class);
    }

    /** @test */
    public function processes_transaction_job_successfully()
    {
        $job = new ProcessTransactionJob($this->transaction);
        $job->handle($this->validationService);

        // Verify transaction was processed
        $this->transaction->refresh();
        $this->assertNotEquals('PENDING', $this->transaction->validation_status);
        
        // Verify job was logged
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $this->transaction->id
        ]);
    }

    /** @test */
    public function dispatches_job_to_queue()
    {
        Queue::fake();

        ProcessTransactionJob::dispatch($this->transaction);

        Queue::assertPushed(ProcessTransactionJob::class, function ($job) {
            return $job->transaction->id === $this->transaction->id;
        });
    }

    /** @test */
    public function handles_validation_errors_gracefully()
    {
        // Create transaction with invalid data
        $invalidTransaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => -100.00, // Invalid negative amount
            'validation_status' => 'PENDING'
        ]);

        $job = new ProcessTransactionJob($invalidTransaction);
        $job->handle($this->validationService);

        // Verify transaction was marked as invalid
        $invalidTransaction->refresh();
        $this->assertEquals('INVALID', $invalidTransaction->validation_status);
        
        // Verify validation errors were logged
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $invalidTransaction->id,
            'validation_status' => 'FAILED'
        ]);
    }

    /** @test */
    public function retries_failed_jobs()
    {
        Queue::fake();

        // Mock a service that will fail
        $mockService = $this->mock(TransactionValidationService::class);
        $mockService->shouldReceive('validateTransaction')
                   ->once()
                   ->andThrow(new \Exception('Service temporarily unavailable'));

        $job = new ProcessTransactionJob($this->transaction);
        
        // Simulate job failure
        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Job should be retried
            $this->assertEquals('Service temporarily unavailable', $e->getMessage());
        }
    }

    /** @test */
    public function processes_multiple_transactions_in_batch()
    {
        Queue::fake();

        // Create multiple transactions
        $transactions = Transaction::factory()->count(5)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        // Dispatch all jobs
        foreach ($transactions as $transaction) {
            ProcessTransactionJob::dispatch($transaction);
        }

        // Verify all jobs were queued
        Queue::assertPushed(ProcessTransactionJob::class, 5);
    }

    /** @test */
    public function updates_transaction_status_progression()
    {
        $job = new ProcessTransactionJob($this->transaction);
        
        // Initially pending
        $this->assertEquals('PENDING', $this->transaction->validation_status);
        
        $job->handle($this->validationService);
        
        // Should be processed
        $this->transaction->refresh();
        $this->assertContains($this->transaction->validation_status, ['VALID', 'INVALID']);
        
        // Verify status history
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $this->transaction->id
        ]);
    }

    /** @test */
    public function logs_processing_performance_metrics()
    {
        Log::shouldReceive('info')
           ->once()
           ->with('Transaction processing completed', \Mockery::type('array'));

        $job = new ProcessTransactionJob($this->transaction);
        $job->handle($this->validationService);

        // Verify performance metrics were logged
        $this->assertDatabaseHas('system_logs', [
            'event_type' => 'TRANSACTION_PROCESSED',
            'level' => 'INFO'
        ]);
    }

    /** @test */
    public function handles_queue_worker_failures()
    {
        // Create job that will fail
        $job = new ProcessTransactionJob($this->transaction);
        
        // Mock validation service to throw exception
        $mockService = $this->mock(TransactionValidationService::class);
        $mockService->shouldReceive('validateTransaction')
                   ->andThrow(new \Exception('Queue worker crashed'));

        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Queue worker crashed', $e->getMessage());
        }
    }

    /** @test */
    public function processes_high_priority_transactions()
    {
        Queue::fake();

        // Create high priority transaction
        $priorityTransaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 10000.00, // High value = high priority
            'validation_status' => 'PENDING'
        ]);

        // Dispatch with high priority
        ProcessTransactionJob::dispatch($priorityTransaction)->onQueue('high');

        Queue::assertPushedOn('high', ProcessTransactionJob::class);
    }

    /** @test */
    public function validates_transaction_during_processing()
    {
        $job = new ProcessTransactionJob($this->transaction);
        $job->handle($this->validationService);

        // Verify validation was performed
        $this->transaction->refresh();
        $this->assertNotEquals('PENDING', $this->transaction->validation_status);
        
        // Check validation results
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $this->transaction->id
        ]);
    }

    /** @test */
    public function handles_database_connection_issues()
    {
        // This test would need to mock database connection failures
        // For now, we'll test that the job handles exceptions gracefully
        
        $mockService = $this->mock(TransactionValidationService::class);
        $mockService->shouldReceive('validateTransaction')
                   ->andThrow(new \Exception('Database connection failed'));

        $job = new ProcessTransactionJob($this->transaction);
        
        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Database connection failed', $e->getMessage());
        }
    }

    /** @test */
    public function processes_transactions_within_time_limits()
    {
        $startTime = microtime(true);
        
        $job = new ProcessTransactionJob($this->transaction);
        $job->handle($this->validationService);
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        // Should process within reasonable time (5 seconds)
        $this->assertLessThan(5.0, $processingTime);
        
        // Verify transaction was processed
        $this->transaction->refresh();
        $this->assertNotEquals('PENDING', $this->transaction->validation_status);
    }

    /** @test */
    public function handles_concurrent_job_processing()
    {
        Queue::fake();

        // Create multiple transactions for concurrent processing
        $transactions = Transaction::factory()->count(10)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        // Dispatch all jobs simultaneously
        foreach ($transactions as $transaction) {
            ProcessTransactionJob::dispatch($transaction);
        }

        // Verify all jobs were queued
        Queue::assertPushed(ProcessTransactionJob::class, 10);
    }

    /** @test */
    public function tracks_job_attempts_and_failures()
    {
        $mockService = $this->mock(TransactionValidationService::class);
        $mockService->shouldReceive('validateTransaction')
                   ->andThrow(new \Exception('Temporary failure'));

        $job = new ProcessTransactionJob($this->transaction);
        $job->attempts = 2; // Simulate second attempt
        
        try {
            $job->handle($mockService);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Verify job tracking
            $this->assertDatabaseHas('transaction_jobs', [
                'transaction_id' => $this->transaction->id,
                'status' => 'failed'
            ]);
        }
    }
}
