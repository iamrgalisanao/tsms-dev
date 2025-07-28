<?php

namespace Database\Factories;

use App\Models\TransactionJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionJobFactory extends Factory
{
    protected $model = TransactionJob::class;

    public function definition()
    {
        return [
            'transaction_id' => $this->faker->uuid,
            'job_status' => $this->faker->randomElement(['QUEUED', 'RUNNING', 'RETRYING', 'COMPLETED', 'PERMANENTLY_FAILED']),
            'last_error' => $this->faker->optional()->sentence,
            'attempts' => $this->faker->numberBetween(1, 3),
            'retry_count' => $this->faker->numberBetween(0, 2),
            'completed_at' => $this->faker->optional()->dateTime,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
