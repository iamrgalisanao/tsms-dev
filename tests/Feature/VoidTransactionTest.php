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

    public function test_refund_transaction_uses_provider_transaction_id()
    {
        $this->actingAs($this->terminal, 'sanctum');

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/refund", [
            'refund_amount' => '25.00',
            'refund_reason' => 'Customer returned item',
            'refund_reference_id' => $this->transaction->transaction_id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->transaction->refresh();
        $this->assertEquals('REFUNDED', $this->transaction->refund_status);
        $this->assertEquals(25.00, (float) $this->transaction->refund_amount);
        $this->assertEquals('Customer returned item', $this->transaction->refund_reason);
        $this->assertEquals($this->transaction->transaction_id, $this->transaction->refund_reference_id);
        $this->assertTrue((bool) $this->transaction->is_refunded);
    }

    public function test_refund_amount_cannot_exceed_gross_sales()
    {
        $this->actingAs($this->terminal, 'sanctum');
        $this->transaction->update(['gross_sales' => 100.00]);

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/refund", [
            'refund_amount' => '100.01',
            'refund_reason' => 'Refund exceeds sale amount',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Refund amount cannot exceed transaction gross sales.',
            ]);

        $this->transaction->refresh();
        $this->assertNull($this->transaction->refund_status);
        $this->assertNull($this->transaction->refund_amount);
        $this->assertFalse((bool) $this->transaction->is_refunded);
    }

    public function test_refund_transaction_not_owned_by_terminal()
    {
        $otherTerminal = PosTerminal::factory()->create([
            'status_id' => 1,
        ]);
        $this->actingAs($otherTerminal, 'sanctum');

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->transaction_id}/refund", [
            'refund_amount' => '25.00',
            'refund_reason' => 'Unauthorized refund attempt',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Transaction not found or does not belong to this terminal',
            ]);
    }
}
