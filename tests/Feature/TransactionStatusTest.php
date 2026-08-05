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
                    'status' => 'queued',
                    'processing_status' => 'queued',
                    'job_status' => 'QUEUED',
                    'validation_status' => $transaction->validation_status ?? 'PENDING',
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
