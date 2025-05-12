<?php

namespace Database\Factories;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntegrationLog>
 */
class IntegrationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'terminal_id' => PosTerminal::factory(),
            'transaction_id' => $this->faker->uuid,
            'request_payload' => ['data' => $this->faker->sentence],
            'response_payload' => ['message' => $this->faker->sentence],
            'status' => $this->faker->randomElement(['SUCCESS', 'FAILED', 'RETRY']),
            'error_message' => $this->faker->optional(0.5)->sentence,
            'http_status_code' => $this->faker->randomElement([200, 201, 400, 401, 403, 404, 500]),
            'source_ip' => $this->faker->ipv4,
            'retry_count' => $this->faker->numberBetween(0, 5),
            'next_retry_at' => $this->faker->optional(0.5)->dateTimeBetween('now', '+1 day'),
            'retry_reason' => $this->faker->optional(0.5)->sentence,
            'validation_status' => $this->faker->optional(0.5)->randomElement(['PASSED', 'FAILED']),
            'response_time' => $this->faker->numberBetween(50, 2000),
            'retry_attempts' => $this->faker->numberBetween(0, 5),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}