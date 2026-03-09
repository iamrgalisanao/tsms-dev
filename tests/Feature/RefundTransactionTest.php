<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosTerminal;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class RefundTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected PosTerminal $terminal;
    protected Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->terminal = PosTerminal::factory()->create([
            'status_id' => 1,
        ]);

        $this->transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'transaction_id' => \Illuminate\Support\Str::uuid()->toString(),
            'transaction_timestamp' => now(),
            'gross_sales' => 1000,
        ]);
    }

    public function test_refund_fails_if_not_same_day()
    {
        $this->actingAs($this->terminal, 'sanctum');

        $yesterday = Carbon::now()->subDay();
        $this->transaction->transaction_timestamp = $yesterday;
        $this->transaction->save();

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->id}/refund", [
            'refund_amount' => 50.00,
            'refund_reason' => 'Late return',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'message' => 'Refunds are only permitted on the same business day',
            ]);
    }

    public function test_refund_fails_for_different_terminal()
    {
        $otherTerminal = PosTerminal::factory()->create([
            'status_id' => 1,
        ]);

        $this->actingAs($otherTerminal, 'sanctum');

        $response = $this->postJson("/api/v1/transactions/{$this->transaction->id}/refund", [
            'refund_amount' => 75.00,
            'refund_reason' => 'Wrong terminal',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'Transaction not found or does not belong to this terminal',
            ]);
    }
}
