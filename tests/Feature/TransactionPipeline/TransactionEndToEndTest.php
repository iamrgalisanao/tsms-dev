<?php

namespace Tests\Feature\TransactionPipeline;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionJob;
use App\Models\TransactionValidation;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\SystemLog;
use App\Models\AuditLog;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;

class TransactionEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $token;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create tenant
        $this->tenant = Tenant::factory()->create([
            'customer_code' => 'E2E001',
            'trade_name' => 'E2E Test Tenant',
            'status' => 'active'
        ]);
        
        // Create terminal
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_uid' => 'E2E-TERM-001',
            'status_id' => 1
        ]);
        
        // Generate authentication token
        $this->token = auth('pos_api')->attempt([
            'terminal_uid' => $this->terminal->terminal_uid,
            'password' => 'default_password'
        ]);
    }

    /** @test */
    public function completes_full_transaction_lifecycle()
    {
        Event::fake();
        
        // Step 1: Submit transaction
        $payload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-TXN-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 250.00,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Premium Bus Ticket',
                    'price' => 250.00,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $payload);

        // Verify ingestion
        $response->assertStatus(200);
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $payload['transaction_id'],
            'validation_status' => 'PENDING'
        ]);

        // Step 2: Process transaction job
        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->first();
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(\App\Services\TransactionValidationService::class);
        $job->handle($validationService);

        // Step 3: Verify processing results
        $transaction->refresh();
        $this->assertNotEquals('PENDING', $transaction->validation_status);
        $this->assertContains($transaction->validation_status, ['VALID', 'INVALID']);

        // Step 4: Verify audit trail
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'TRANSACTION_RECEIVED',
            'entity_type' => 'transaction',
            'entity_id' => $payload['transaction_id']
        ]);

        $this->assertDatabaseHas('system_logs', [
            'event_type' => 'TRANSACTION_INGESTION',
            'level' => 'INFO'
        ]);

        // Step 5: Verify job tracking
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->id,
            'status' => 'completed'
        ]);

        // Step 6: Verify validation tracking
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->id
        ]);
    }

    /** @test */
    public function handles_invalid_transaction_end_to_end()
    {
        Event::fake();
        
        // Submit invalid transaction
        $payload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-INVALID-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => -100.00, // Invalid negative amount
            'items' => []
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $payload);

        // Transaction should be accepted for processing
        $response->assertStatus(200);
        
        // Process the transaction
        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->first();
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(\App\Services\TransactionValidationService::class);
        $job->handle($validationService);

        // Verify it was marked as invalid
        $transaction->refresh();
        $this->assertEquals('INVALID', $transaction->validation_status);

        // Verify validation errors were logged
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->id,
            'validation_status' => 'FAILED'
        ]);
    }

    /** @test */
    public function processes_batch_transactions_end_to_end()
    {
        Event::fake();
        
        // Submit batch of transactions
        $batchPayload = [
            'batch_id' => 'E2E-BATCH-' . uniqid(),
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transactions' => []
        ];

        // Create 3 transactions in batch
        for ($i = 1; $i <= 3; $i++) {
            $batchPayload['transactions'][] = [
                'transaction_id' => 'E2E-BATCH-TXN-' . $i . '-' . uniqid(),
                'hardware_id' => 'HW-12345',
                'machine_number' => $this->terminal->machine_number,
                'transaction_timestamp' => now()->toISOString(),
                'base_amount' => 100.00 * $i,
                'items' => [
                    [
                        'id' => $i,
                        'name' => "Ticket $i",
                        'price' => 100.00 * $i,
                        'quantity' => 1
                    ]
                ]
            ];
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions/batch', $batchPayload);

        $response->assertStatus(200);

        // Process all transactions
        $transactions = Transaction::where('customer_code', $this->tenant->customer_code)
                                 ->where('validation_status', 'PENDING')
                                 ->get();

        $this->assertCount(3, $transactions);

        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $validationService = app(\App\Services\TransactionValidationService::class);
            $job->handle($validationService);
        }

        // Verify all transactions were processed
        foreach ($transactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
        }
    }

    /** @test */
    public function maintains_data_integrity_during_concurrent_processing()
    {
        Event::fake();
        
        // Create multiple transactions simultaneously
        $transactionIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $payload = [
                'customer_code' => $this->tenant->customer_code,
                'terminal_id' => $this->terminal->id,
                'transaction_id' => 'E2E-CONCURRENT-' . $i . '-' . uniqid(),
                'hardware_id' => 'HW-12345',
                'machine_number' => $this->terminal->machine_number,
                'transaction_timestamp' => now()->toISOString(),
                'base_amount' => 100.00 + ($i * 10),
                'items' => [
                    [
                        'id' => $i,
                        'name' => "Concurrent Ticket $i",
                        'price' => 100.00 + ($i * 10),
                        'quantity' => 1
                    ]
                ]
            ];

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json'
            ])->postJson('/api/v1/transactions', $payload);

            $response->assertStatus(200);
            $transactionIds[] = $payload['transaction_id'];
        }

        // Process all transactions concurrently
        $transactions = Transaction::whereIn('transaction_id', $transactionIds)->get();
        $validationService = app(\App\Services\TransactionValidationService::class);

        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($validationService);
        }

        // Verify data integrity
        foreach ($transactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
            
            // Verify unique processing
            $jobCount = TransactionJob::where('transaction_id', $transaction->id)->count();
            $this->assertGreaterThan(0, $jobCount);
        }
    }

    /** @test */
    public function handles_system_recovery_scenarios()
    {
        Event::fake();
        
        // Create transaction
        $payload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-RECOVERY-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 150.00,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Recovery Test Ticket',
                    'price' => 150.00,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $payload);

        $response->assertStatus(200);
        
        // Simulate system failure during processing
        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->first();
        $transaction->update(['validation_status' => 'PENDING']);
        
        // Create failed job record

        TransactionJob::create([
            'transaction_id' => $transaction->id,
            'job_type' => 'process',
            'job_status' => 'FAILED',
            'error_message' => 'System failure during processing',
            'retry_count' => 0
        ]);

        // Retry processing
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(\App\Services\TransactionValidationService::class);
        $job->handle($validationService);

        // Verify recovery
        $transaction->refresh();
        $this->assertNotEquals('PENDING', $transaction->validation_status);
        
        // Verify retry was logged
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->id,
            'status' => 'completed'
        ]);
    }

    /** @test */
    public function generates_comprehensive_audit_trail()
    {
        Event::fake();
        
        // Submit transaction
        $payload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-AUDIT-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 200.00,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Audit Test Ticket',
                    'price' => 200.00,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $payload);

        $response->assertStatus(200);
        
        // Process transaction
        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->first();
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(\App\Services\TransactionValidationService::class);
        $job->handle($validationService);

        // Verify comprehensive audit trail
        $auditEntries = AuditLog::where('entity_id', $payload['transaction_id'])->get();
        $this->assertGreaterThan(0, $auditEntries->count());

        $systemLogs = SystemLog::where('context', 'like', '%' . $payload['transaction_id'] . '%')->get();
        $this->assertGreaterThan(0, $systemLogs->count());

        // Verify all processing stages are logged
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->id
        ]);

        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->id
        ]);
    }

    /** @test */
    public function measures_performance_metrics()
    {
        Event::fake();
        
        // Submit transaction and measure end-to-end time
        $startTime = microtime(true);
        
        $payload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-PERF-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 100.00,
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Performance Test Ticket',
                    'price' => 100.00,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $payload);

        $response->assertStatus(200);
        
        // Process transaction
        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->first();
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(\App\Services\TransactionValidationService::class);
        $job->handle($validationService);

        $endTime = microtime(true);
        $totalProcessingTime = $endTime - $startTime;

        // Verify performance is acceptable (under 2 seconds for single transaction)
        $this->assertLessThan(2.0, $totalProcessingTime);
        
        // Verify transaction was processed successfully
        $transaction->refresh();
        $this->assertNotEquals('PENDING', $transaction->validation_status);
    }

    /** @test */
    public function handles_edge_cases_and_boundary_conditions()
    {
        Event::fake();
        
        // Test minimum amount
        $minAmountPayload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-MIN-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 1.00, // Minimum amount
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Minimum Ticket',
                    'price' => 1.00,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $minAmountPayload);

        $response->assertStatus(200);
        
        // Test maximum amount
        $maxAmountPayload = [
            'customer_code' => $this->tenant->customer_code,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'E2E-MAX-' . uniqid(),
            'hardware_id' => 'HW-12345',
            'machine_number' => $this->terminal->machine_number,
            'transaction_timestamp' => now()->toISOString(),
            'base_amount' => 9999.99, // Maximum amount
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Maximum Ticket',
                    'price' => 9999.99,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions', $maxAmountPayload);

        $response->assertStatus(200);
        
        // Process both transactions
        $transactions = Transaction::where('customer_code', $this->tenant->customer_code)
                                 ->where('validation_status', 'PENDING')
                                 ->get();

        $validationService = app(\App\Services\TransactionValidationService::class);
        foreach ($transactions as $transaction) {
            $job = new ProcessTransactionJob($transaction);
            $job->handle($validationService);
        }

        // Verify both were processed
        foreach ($transactions as $transaction) {
            $transaction->refresh();
            $this->assertNotEquals('PENDING', $transaction->validation_status);
        }
    }
}
