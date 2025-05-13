<?php

namespace Database\Factories;

use App\Models\SecurityReport;
use App\Models\SecurityReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityReportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SecurityReport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'security_report_template_id' => null,
            'name' => $this->faker->sentence(3),
            'status' => $this->faker->randomElement(['generating', 'completed', 'failed']),
            'filters' => [
                'event_type' => $this->faker->randomElement(['authentication', 'authorization', 'data_access', 'system']),
                'severity' => $this->faker->randomElement(['info', 'warning', 'critical']),
                'from' => now()->subDays(30)->toDateTimeString(),
                'to' => now()->toDateTimeString()
            ],
            'generated_by' => 1,
            'from_date' => now()->subDays(30),
            'to_date' => now(),
            'format' => $this->faker->randomElement(['html', 'pdf', 'csv', 'json']),
            'results' => null,
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Configure the model factory for a completed report.
     *
     * @return static
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'completed',
                'results' => [
                    'total_events' => rand(10, 100),
                    'events_by_type' => [
                        'authentication' => rand(5, 30),
                        'authorization' => rand(5, 30),
                        'data_access' => rand(5, 30),
                    ],
                    'events_by_severity' => [
                        'info' => rand(5, 50),
                        'warning' => rand(5, 30),
                        'critical' => rand(1, 10),
                    ],
                    'events_list' => [],
                    'generated_at' => now()->toIso8601String(),
                ]
            ];
        });
    }

    /**
     * Configure the model factory for a failed report.
     *
     * @return static
     */
    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'failed',
                'error_message' => $this->faker->sentence(),
            ];
        });
    }

    /**
     * Configure the model factory for a report with a template.
     *
     * @param int|null $templateId
     * @return static
     */
    public function withTemplate(int $templateId = null): static
    {
        return $this->state(function (array $attributes) use ($templateId) {
            $template = $templateId 
                ? SecurityReportTemplate::find($templateId) 
                : SecurityReportTemplate::factory()->create();
                
            return [
                'security_report_template_id' => $template->id,
            ];
        });
    }
}