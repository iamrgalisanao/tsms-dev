<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PosProviderFactory extends Factory
{
    protected $model = \App\Models\PosProvider::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}