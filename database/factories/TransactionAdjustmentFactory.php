<?php

namespace Database\Factories;

use App\Models\TransactionAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionAdjustmentFactory extends Factory
{
    protected $model = TransactionAdjustment::class;

    public function definition()
    {
        return [
            'transaction_id' => $this->faker->uuid,
            'adjustment_type' => $this->faker->randomElement(['senior_discount', 'service_charge', 'promo_discount']),
            'amount' => $this->faker->randomFloat(2, 1, 100),
            'created_at' => now(),
        ];
    }
}
