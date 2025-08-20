<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VoidTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $transaction;
    protected $checksumService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable middleware for testing
        $this->withoutMiddleware();
        
        // Mock WebAppForwardingService to avoid external dependencies
        $this->mock(\App\Services\WebAppForwardingService::class, function ($mock) {
            $mock->shouldReceive('forwardVoidedTransaction')->andReturn(true);
        });
        
        // Create test terminal with correct schema
        $this->terminal = PosTerminal::factory()->create([
            'status_id' => 1 // Use status_id instead of status
        ]);
        
        // Create test transaction
        $this->transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'transaction_id' => \Illuminate\Support\Str::uuid()->toString(),
            'validation_status' => 'VALID'
        ]);
        
        $this->checksumService = new PayloadChecksumService();
    }

    public function test_successful_void_transaction()
    {
        // Use actingAs to bypass middleware
        $this->actingAs($this->terminal, 'sanctum');

        $payload = [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Customer requested cancellation',
        ];
        $checksum = $this->checksumService->computeChecksum($payload);

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/void", [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Customer requested cancellation',
            'payload_checksum' => $checksum,
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Transaction voided successfully by POS',
                    'transaction_id' => $this->transaction->transaction_id,
                ]);

        // Verify transaction is voided in database
        $this->transaction->refresh();
        $this->assertNotNull($this->transaction->voided_at);
        $this->assertEquals('Customer requested cancellation', $this->transaction->void_reason);
    }

    public function test_void_already_voided_transaction()
    {
        // Pre-void the transaction
        $this->transaction->void('Already voided');

        $this->actingAs($this->terminal, 'sanctum');

        $payload = [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Trying to void again',
        ];
        $checksum = $this->checksumService->computeChecksum($payload);

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/void", [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Trying to void again',
            'payload_checksum' => $checksum,
        ]);

        $response->assertStatus(409)
                ->assertJson([
                    'success' => false,
                    'message' => 'Transaction already voided',
                ]);
    }

    public function test_void_with_invalid_checksum()
    {
        $this->actingAs($this->terminal, 'sanctum');

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/void", [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Test void',
            'payload_checksum' => str_repeat('x', 64), // Valid length but wrong checksum
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid payload checksum',
                ]);
    }

    public function test_void_transaction_not_owned_by_terminal()
    {
        // Create another terminal
        $otherTerminal = PosTerminal::factory()->create([
            'status_id' => 1
        ]);
        $this->actingAs($otherTerminal, 'sanctum');

        $payload = [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Unauthorized void attempt',
        ];
        $checksum = $this->checksumService->computeChecksum($payload);

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/void", [
            'transaction_id' => $this->transaction->transaction_id,
            'void_reason' => 'Unauthorized void attempt',
            'payload_checksum' => $checksum,
        ]);

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Transaction not found or does not belong to this terminal',
                ]);
    }
}
