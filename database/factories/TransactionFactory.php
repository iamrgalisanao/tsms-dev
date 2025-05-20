<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        
        return [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN-' . $this->faker->unique()->numberBetween(1000, 9999),
            'hardware_id' => 'HW-' . $this->faker->unique()->numberBetween(1000, 9999),
            'validation_status' => 'PENDING',
            'transaction_timestamp' => now(),
            'gross_sales' => $this->faker->randomFloat(2, 100, 10000),
            'net_sales' => $this->faker->randomFloat(2, 100, 10000),
            'vatable_sales' => $this->faker->randomFloat(2, 100, 10000),
            'vat_exempt_sales' => $this->faker->randomFloat(2, 0, 1000),
            'vat_amount' => $this->faker->randomFloat(2, 0, 1000),
            'transaction_count' => 1,
            'payload_checksum' => md5($this->faker->uuid)
        ];
    }
}