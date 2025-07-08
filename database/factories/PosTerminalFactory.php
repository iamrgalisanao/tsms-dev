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
            'tenant_id' => \App\Models\Tenant::factory(),
            'serial_number' => $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'machine_number' => $this->faker->unique()->regexify('[A-Z0-9]{6}'),
            'supports_guest_count' => $this->faker->boolean,
            'pos_type_id' => null, // Set to null to avoid foreign key issues
            'integration_type_id' => null, // Set to null to avoid foreign key issues
            'auth_type_id' => null, // Set to null to avoid foreign key issues
            'status_id' => 1, // Use 'active' status (id: 1)
            'registered_at' => now(),
            'last_seen_at' => $this->faker->optional()->dateTimeBetween('-1 week', 'now'),
            'heartbeat_threshold' => 300,
            'expires_at' => $this->faker->optional()->dateTimeBetween('+1 week', '+1 year'),
            'callback_url' => $this->faker->optional()->url,
            'notifications_enabled' => $this->faker->boolean,
            'notification_preferences' => json_encode([
                'receive_validation_results' => true,
                'receive_batch_results' => true,
                'include_details' => true
            ]),
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