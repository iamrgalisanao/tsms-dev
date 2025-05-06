<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PosTerminal>
 */
class PosTerminalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'terminal_uid' => $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'status' => 'active',
            'is_sandbox' => false,
            'webhook_url' => $this->faker->url,
            'max_retries' => 3,
            'retry_interval_sec' => 300,
            'retry_enabled' => true,
            'jwt_token' => $this->faker->sha256,
            'registered_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    /**
     * Indicate that the terminal is for sandbox testing.
     */
    public function sandbox(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'is_sandbox' => true
            ];
        });
    }
}
