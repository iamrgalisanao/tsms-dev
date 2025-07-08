<?php

namespace Tests\Feature\TransactionPipeline;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TransactionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $validationService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->tenant = Tenant::factory()->create([
            'customer_code' => 'PERF001',
            'status' => 'active'
        ]);
        
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status_id' => 1
        ]);
        
        $this->validationService = app(\App\Services\TransactionValidationService::class);
    }

    /** @test */
    public function processes_single_transaction_within_time_limit()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        $startTime = microtime(true);
        
        $job = new ProcessTransactionJob($transaction);
        $job->handle($this->validationService);
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        // Should process within 500ms
        $this->assertLessThan(0.5, $processingTime, 'Single transaction should process within 500ms');
        
        $transaction->refresh();
        $this->assertNotEquals('PENDING', $transaction->validation_status);
    }

    /** @test */
    public function processes_batch_of_transactions_efficiently()
    {
        $batchSize = 10;
        $transactions = Transaction::factory()->count($batchSize)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        $startTime = microtime(true);
        
        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        
        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;
        $averageProcessingTime = $totalProcessingTime / $batchSize;
        
        // Average should be under 200ms per transaction
        $this->assertLessThan(0.2, $averageProcessingTime, 'Average processing time should be under 200ms');
        
        // Total batch should be under 3 seconds
        $this->assertLessThan(3.0, $totalProcessingTime, 'Batch of 10 should process under 3 seconds');
        
        // Verify all transactions were processed
        foreach ($transactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
        }
    }

    /** @test */
    public function handles_high_volume_concurrent_transactions()
    {
        $transactionCount = 50;
        $transactions = Transaction::factory()->count($transactionCount)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        $startTime = microtime(true);
        
        // Process transactions in chunks to simulate concurrent processing
        $chunks = $transactions->chunk(10);
        foreach ($chunks as $chunk) {
            foreach ($chunk as $transaction) {
                $job = new ProcessTransactionJob($transaction);
                $job->handle($this->validationService);
            }
        }
        
        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;
        
        // Should process 50 transactions within 15 seconds
        $this->assertLessThan(15.0, $totalProcessingTime, '50 transactions should process within 15 seconds');
        
        // Verify all transactions were processed
        $processedCount = Transaction::where('terminal_id', $this->terminal->id)
                                   ->where('validation_status', '!=', 'PENDING')
                                   ->count();
        $this->assertEquals($transactionCount, $processedCount);
    }

    /** @test */
    public function maintains_database_performance_under_load()
    {
        $transactionCount = 100;
        
        // Monitor database query count
        $queryCount = 0;
        DB::listen(function($query) use (&$queryCount) {
            $queryCount++;
        });
        
        $transactions = Transaction::factory()->count($transactionCount)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);

        $startTime = microtime(true);
        
        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        
        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;
        
        // Should maintain reasonable query count (not exceed 500 queries for 100 transactions)
        $this->assertLessThan(500, $queryCount, 'Query count should be reasonable');
        
        // Should complete within 30 seconds
        $this->assertLessThan(30.0, $totalProcessingTime, '100 transactions should process within 30 seconds');
    }

    /** @test */
    public function handles_memory_usage_efficiently()
    {
        $transactionCount = 200;
        
        $startMemory = memory_get_usage(true);
        
        // Process transactions in batches to manage memory
        $batchSize = 20;
        for ($i = 0; $i < $transactionCount; $i += $batchSize) {
            $batch = Transaction::factory()->count($batchSize)->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'validation_status' => 'PENDING'
            ]);
            
            foreach ($batch as $transaction) {
                $job = new ProcessTransactionJob($transaction);
                $job->handle($this->validationService);
            }
            
            // Clear memory after each batch
            unset($batch);
            if ($i % 40 == 0) {
                gc_collect_cycles();
            }
        }
        
        $endMemory = memory_get_usage(true);
        $memoryUsed = $endMemory - $startMemory;
        
        // Should not use more than 50MB for 200 transactions
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be under 50MB');
    }

    /** @test */
    public function validates_transaction_throughput()
    {
        $duration = 5; // 5 seconds
        $transactions = [];
        
        $startTime = microtime(true);
        $endTime = $startTime + $duration;
        
        while (microtime(true) < $endTime) {
            $transaction = Transaction::factory()->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'validation_status' => 'PENDING'
            ]);
            
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
            
            $transactions[] = $transaction;
            
            // Small delay to prevent overwhelming the system
            usleep(10000); // 10ms
        }
        
        $actualDuration = microtime(true) - $startTime;
        $throughput = count($transactions) / $actualDuration;
        
        // Should achieve at least 10 transactions per second
        $this->assertGreaterThan(10, $throughput, 'Should achieve at least 10 transactions/second');
        
        // Verify all transactions were processed
        foreach ($transactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
        }
    }

    /** @test */
    public function handles_peak_load_scenarios()
    {
        // Simulate peak load with varying transaction sizes
        $peakTransactions = [];
        
        // Small transactions (typical)
        for ($i = 0; $i < 30; $i++) {
            $peakTransactions[] = Transaction::factory()->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'base_amount' => 50.00,
                'validation_status' => 'PENDING'
            ]);
        }
        
        // Medium transactions
        for ($i = 0; $i < 15; $i++) {
            $peakTransactions[] = Transaction::factory()->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'base_amount' => 500.00,
                'validation_status' => 'PENDING'
            ]);
        }
        
        // Large transactions
        for ($i = 0; $i < 5; $i++) {
            $peakTransactions[] = Transaction::factory()->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'base_amount' => 5000.00,
                'validation_status' => 'PENDING'
            ]);
        }
        
        $startTime = microtime(true);
        
        // Process all peak transactions
        foreach ($peakTransactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        
        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;
        
        // Should handle peak load within 20 seconds
        $this->assertLessThan(20.0, $totalProcessingTime, 'Peak load should process within 20 seconds');
        
        // Verify all transactions were processed
        foreach ($peakTransactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
        }
    }

    /** @test */
    public function measures_cache_performance()
    {
        Cache::flush();
        
        $transactions = Transaction::factory()->count(20)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);
        
        // First pass - cold cache
        $startTime = microtime(true);
        foreach ($transactions->take(10) as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        $coldCacheTime = microtime(true) - $startTime;
        
        // Second pass - warm cache
        $startTime = microtime(true);
        foreach ($transactions->skip(10)->take(10) as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        $warmCacheTime = microtime(true) - $startTime;
        
        // Warm cache should be at least 10% faster
        $this->assertLessThan($coldCacheTime * 0.9, $warmCacheTime, 'Warm cache should improve performance');
    }

    /** @test */
    public function handles_database_connection_pool_efficiently()
    {
        $transactionCount = 50;
        
        $transactions = Transaction::factory()->count($transactionCount)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);
        
        $startTime = microtime(true);
        
        // Process transactions rapidly to test connection pooling
        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
        }
        
        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;
        
        // Should handle without connection issues
        $this->assertLessThan(20.0, $totalProcessingTime, 'Should handle connection pooling efficiently');
        
        // Verify all transactions were processed
        $processedCount = Transaction::where('terminal_id', $this->terminal->id)
                                   ->where('validation_status', '!=', 'PENDING')
                                   ->count();
        $this->assertEquals($transactionCount, $processedCount);
    }

    /** @test */
    public function monitors_system_resource_usage()
    {
        $transactionCount = 100;
        
        $initialMemory = memory_get_usage(true);
        $initialPeakMemory = memory_get_peak_usage(true);
        
        $transactions = Transaction::factory()->count($transactionCount)->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'validation_status' => 'PENDING'
        ]);
        
        $startTime = microtime(true);
        
        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($this->validationService);
            
            // Monitor memory usage periodically
            if (memory_get_usage(true) > $initialMemory * 2) {
                $this->fail('Memory usage exceeded acceptable limits');
            }
        }
        
        $endTime = microtime(true);
        $finalMemory = memory_get_usage(true);
        $finalPeakMemory = memory_get_peak_usage(true);
        
        $memoryIncrease = $finalMemory - $initialMemory;
        $peakMemoryIncrease = $finalPeakMemory - $initialPeakMemory;
        
        // Log resource usage for monitoring
        $this->addToAssertionCount(1); // Ensure test counts as assertion
        
        // Memory increase should be reasonable
        $this->assertLessThan(100 * 1024 * 1024, $memoryIncrease, 'Memory increase should be under 100MB');
        $this->assertLessThan(150 * 1024 * 1024, $peakMemoryIncrease, 'Peak memory increase should be under 150MB');
    }
}
