<?php

namespace Database\Factories;

use App\Models\SecurityAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityAlertFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SecurityAlert::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(2),
            'severity' => $this->faker->randomElement(['info', 'warning', 'critical']),
            'source' => $this->faker->randomElement(['system', 'user', 'integration', 'automated_rule']),
            'alert_type' => $this->faker->randomElement(['anomaly', 'threshold', 'pattern', 'rule_based']),
            'context' => [
                'ip_address' => $this->faker->ipv4,
                'user_agent' => $this->faker->userAgent,
                'location' => $this->faker->city . ', ' . $this->faker->country,
                'related_entity' => $this->faker->randomElement(['user', 'transaction', 'integration', null]),
                'entity_id' => $this->faker->numberBetween(1, 1000)
            ],
            'status' => $this->faker->randomElement(['new', 'acknowledged', 'resolved', 'dismissed']),
            'acknowledged_by' => null,
            'acknowledged_at' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure the model factory for a critical alert.
     *
     * @return static
     */
    public function critical(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'severity' => 'critical',
            ];
        });
    }

    /**
     * Configure the model factory for a warning alert.
     *
     * @return static
     */
    public function warning(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'severity' => 'warning',
            ];
        });
    }

    /**
     * Configure the model factory for an acknowledged alert.
     *
     * @param int|null $userId
     * @return static
     */
    public function acknowledged(?int $userId = 1): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'status' => 'acknowledged',
                'acknowledged_by' => $userId,
                'acknowledged_at' => now(),
            ];
        });
    }

    /**
     * Configure the model factory for a resolved alert.
     *
     * @param int|null $userId
     * @return static
     */
    public function resolved(?int $userId = 1): static
    {
        return $this->state(function (array $attributes) use ($userId) {
            return [
                'status' => 'resolved',
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ];
        });
    }
}