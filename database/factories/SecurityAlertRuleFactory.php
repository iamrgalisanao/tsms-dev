<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\SecurityAlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityAlertRuleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SecurityAlertRule::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->words(3, true),
            'description' => $this->faker->sentence(),
            'event_type' => $this->faker->randomElement([
                'login_failure',
                'suspicious_activity',
                'rate_limit_breach',
                'circuit_breaker_trip',
                'unauthorized_access',
                'permission_violation'
            ]),
            'threshold' => $this->faker->numberBetween(1, 10),
            'window_minutes' => $this->faker->numberBetween(1, 60),
            'action' => $this->faker->randomElement(['log', 'notify', 'block']),
            'notification_channels' => ['email'], // Default to email notifications
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
        ];
    }

    /**
     * Indicate that the alert rule is active.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function active()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => true,
            ];
        });
    }

    /**
     * Indicate that the alert rule is inactive.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}
