<?php

namespace Database\Factories;

use App\Models\SecurityReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityReportTemplateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SecurityReportTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(1),
            'type' => $this->faker->randomElement(['security_events', 'failed_transactions', 'circuit_breaker_trips', 'login_attempts', 'security_alerts', 'comprehensive']),
            'filters' => [
                'event_type' => $this->faker->randomElement(['authentication', 'authorization', 'data_access', 'system']),
                'severity' => $this->faker->randomElement(['info', 'warning', 'critical'])
            ],
            'columns' => ['timestamp', 'event_type', 'severity', 'source_ip', 'user_id', 'context'],
            'format' => $this->faker->randomElement(['html', 'pdf', 'csv', 'json']),
            'is_scheduled' => $this->faker->boolean(20),
            'schedule_frequency' => $this->faker->randomElement(['daily', 'weekly', 'monthly', null]),
            'notification_settings' => [
                'email' => $this->faker->boolean(50),
                'recipients' => [$this->faker->email]
            ],
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure the model factory for a system template.
     *
     * @return static
     */
    public function system(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_system' => true,
            ];
        });
    }

    /**
     * Configure the model factory for a scheduled template.
     *
     * @return static
     */
    public function scheduled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'is_scheduled' => true,
                'schedule_frequency' => $this->faker->randomElement(['daily', 'weekly', 'monthly']),
            ];
        });
    }

    /**
     * Configure the model factory for a specific report type.
     *
     * @param string $type
     * @return static
     */
    public function ofType(string $type): static
    {
        return $this->state(function (array $attributes) use ($type) {
            return [
                'type' => $type,
            ];
        });
    }
}
