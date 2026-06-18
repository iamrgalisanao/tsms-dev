<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Transaction;

class TransactionDiscountsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_discounts_are_mass_assignable_and_persisted()
    {
        // Ensure required parent records exist (factories) and use their ids
        $tenant = \Database\Factories\TenantFactory::new()->create();
        $terminal = \Database\Factories\PosTerminalFactory::new()->create(['tenant_id' => $tenant->id]);

        // Minimal required attributes to create a transaction record
        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'test-tx-' . uniqid(),
            'transaction_timestamp' => now()->toDateTimeString(),
            'gross_sales' => 100.00,
            'net_sales' => 88.00,
            'payload_checksum' => str_repeat('a', 64),
            'promo_discount' => 5.50,
            'senior_discount' => 2.00,
            'pwd_discount' => 1.25,
        ]);

        $this->assertDatabaseHas('transactions', [
            'id' => $tx->id,
            'promo_discount' => '5.50',
            'senior_discount' => '2.00',
            'pwd_discount' => '1.25',
        ]);
    }
}
