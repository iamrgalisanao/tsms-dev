<?php

namespace Database\Factories;

use App\Models\ValidationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class ValidationStatusFactory extends Factory
{
    protected $model = ValidationStatus::class;

    public function definition()
    {
        return [
            'name' => $this->faker->unique()->randomElement(['PENDING', 'VALID', 'ERROR']),
            'description' => $this->faker->sentence,
        ];
    }
}
