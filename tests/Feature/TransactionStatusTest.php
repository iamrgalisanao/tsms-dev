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
        // Create a test transaction
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 950.00,
            'vatable_sales' => 850.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 102.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
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
                    'validation_status' => 'PENDING'
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