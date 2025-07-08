<?php

namespace Database\Factories;

use App\Models\TransactionTax;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionTaxFactory extends Factory
{
    protected $model = TransactionTax::class;

    public function definition()
    {
        return [
            'transaction_id' => $this->faker->uuid,
            'tax_type' => $this->faker->randomElement(['VAT', 'VAT_EXEMPT', 'OTHER_TAX']),
            'amount' => $this->faker->randomFloat(2, 1, 50),
            'created_at' => now(),
        ];
    }
}
