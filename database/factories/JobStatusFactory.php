<?php

namespace Database\Factories;

use App\Models\JobStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobStatusFactory extends Factory
{
    protected $model = JobStatus::class;

    public function definition()
    {
        return [
            'name' => $this->faker->unique()->randomElement(['QUEUED', 'PROCESSING', 'COMPLETED', 'FAILED']),
            'description' => $this->faker->sentence,
        ];
    }
}
