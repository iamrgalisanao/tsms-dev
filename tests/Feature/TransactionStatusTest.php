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

    public function test_status_does_not_fall_back_to_internal_numeric_id_for_uuid_like_transaction_id()
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $otherTerminal = PosTerminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);

        Transaction::create([
            'id' => 3,
            'tenant_id' => $otherTenant->id,
            'terminal_id' => $otherTerminal->id,
            'transaction_id' => 'b2222222-d1d5-4f81-8473-5d8f013bdcfe',
            'hardware_id' => 'HW-OTHER',
            'transaction_timestamp' => now()->subDay(),
            'gross_sales' => 200.00,
            'net_sales' => 180.00,
            'customer_code' => 'C-TEST',
            'payload_checksum' => md5('other-transaction'),
            'job_status' => 'COMPLETED',
            'validation_status' => 'VALID',
        ]);

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => '3a18510e-d82e-4832-9a97-6f6f291d8c04',
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 305.00,
            'net_sales' => 305.00,
            'customer_code' => 'CUST-001',
            'payload_checksum' => md5('tenant-120-like-transaction'),
            'job_status' => 'COMPLETED',
            'validation_status' => 'VALID',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson("/api/v1/transactions/{$transaction->transaction_id}/status");

        $response->assertOk()
            ->assertJsonPath('data.transaction_id', $transaction->transaction_id)
            ->assertJsonMissing([
                'transaction_id' => 'b2222222-d1d5-4f81-8473-5d8f013bdcfe',
            ]);
    }

    public function test_status_returns_404_instead_of_internal_id_match_for_missing_numeric_prefixed_uuid()
    {
        Transaction::create([
            'id' => 3,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'b2222222-d1d5-4f81-8473-5d8f013bdcfe',
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 200.00,
            'net_sales' => 180.00,
            'customer_code' => 'C-TEST',
            'payload_checksum' => md5('internal-id-collision'),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/transactions/3-not-a-real-transaction-id/status');

        $response->assertNotFound()
            ->assertJson([
                'success' => false,
                'status' => 'error',
                'message' => 'Transaction not found'
            ]);
    }
}
