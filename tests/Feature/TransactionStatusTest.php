<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

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

        // Generate JWT token
        $this->token = JWTAuth::fromUser($this->terminal);
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
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    // If validation status is now in a related table, fetch accordingly
                    'validation_status' => $transaction->validation_status ?? 'PENDING'
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
                'message' => 'Transaction not found'
            ]);
    }
}