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
        $tenant = \App\Models\Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'serial_number' => $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'machine_number' => $this->faker->unique()->regexify('[A-Z0-9]{6}'),
            'supports_guest_count' => $this->faker->boolean,
            'pos_type_id' => null,
            'integration_type_id' => null,
            'auth_type_id' => null,
            'status_id' => 1, // Use 'active' status (id: 1)
        ]);
        return [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN-' . $this->faker->unique()->uuid,
            'hardware_id' => 'HW-' . $this->faker->unique()->numberBetween(1000, 9999),
            'transaction_timestamp' => now(),
            'base_amount' => $this->faker->randomFloat(2, 100, 10000),
            'customer_code' => 'TEST001',
            'payload_checksum' => md5($this->faker->uuid),
            'validation_status' => 'PENDING',
        ];
    }
}