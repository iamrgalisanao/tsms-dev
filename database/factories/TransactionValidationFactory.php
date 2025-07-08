<?php

namespace Database\Factories;

use App\Models\TransactionValidation;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionValidationFactory extends Factory
{
    protected $model = TransactionValidation::class;

    public function definition()
    {
        return [
            'transaction_id' => $this->faker->uuid,
            'validation_status_code' => $this->faker->randomElement(['PENDING', 'VALID', 'INVALID', 'REVIEW_REQUIRED']),
            'validation_details' => $this->faker->sentence,
            'error_code' => $this->faker->optional()->word,
            'validated_at' => $this->faker->dateTime,
        ];
    }
}
