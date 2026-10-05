<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransactionStatusTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $tenant;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data with existing tenant and terminal
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);

        $this->token = $this->terminal->createToken('transaction-status-test', ['transaction:read'])->plainTextToken;
    }

    public function test_can_retrieve_transaction_status()
    {
        // Create a test transaction using normalized schema fields
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.00,
            'customer_code' => 'CUST-001',
            'payload_checksum' => md5('test'),
            // Add any other normalized fields required by your schema
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson("/api/v1/transactions/{$transaction->transaction_id}/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'success',
                'message' => 'Status lookup succeeded',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => 'pending',
                    'processing_status' => 'pending',
                    'job_status' => 'PENDING',
                    'validation_status' => 'PENDING',
                ]
            ]);
    }

    public function test_status_reflects_completed_job_state()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-COMPLETED-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.00,
            'customer_code' => 'CUST-001',
            'payload_checksum' => md5('test-completed'),
            'job_status' => 'COMPLETED',
            'validation_status' => 'VALID',
            'completed_at' => now(),
            'job_attempts' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson("/api/v1/transactions/{$transaction->transaction_id}/status");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'success',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => 'completed',
                    'processing_status' => 'completed',
                    'job_status' => 'COMPLETED',
                    'validation_status' => 'VALID',
                    'attempts' => 1,
                ]
            ]);
    }

    public function test_status_keeps_validation_pending_while_job_is_processing()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-PROCESSING-'.time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.00,
            'customer_code' => 'CUST-001',
            'payload_checksum' => md5('test-processing'),
            'job_status' => Transaction::JOB_STATUS_PROCESSING,
            'validation_status' => Transaction::VALIDATION_STATUS_PENDING,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/transactions/{$transaction->transaction_id}/status")
            ->assertOk()
            ->assertJsonPath('data.job_status', 'PROCESSING')
            ->assertJsonPath('data.validation_status', 'PENDING');
    }

    public function test_status_maps_error_validation_to_invalid()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-INVALID-'.time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.00,
            'customer_code' => 'CUST-001',
            'payload_checksum' => md5('test-invalid'),
            'job_status' => Transaction::JOB_STATUS_FAILED,
            // The transactions.validation_status column enum stores ERROR/INVALID;
            // ERROR exercises the FAILED/ERROR -> INVALID external mapping.
            'validation_status' => 'ERROR',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/transactions/{$transaction->transaction_id}/status")
            ->assertOk()
            ->assertJsonPath('data.job_status', 'FAILED')
            ->assertJsonPath('data.validation_status', 'INVALID');
    }

    public function test_returns_404_for_nonexistent_transaction()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/transactions/NONEXISTENT/status');

        $response->assertNotFound()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Transaction not found'
            ]);
    }
}
