<?php

namespace Database\Factories;

use App\Models\TaxBackfillRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaxBackfillRunFactory extends Factory
{
    protected $model = TaxBackfillRun::class;

    public function definition(): array
    {
        $windowStart = $this->faker->dateTimeBetween('-60 days', '-31 days');
        $windowEnd = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'mode' => TaxBackfillRun::MODE_DRY_RUN,
            'scanned_count' => 0,
            'reconstructed_count' => 0,
            'skipped_existing_count' => 0,
            'quarantined_count' => 0,
            'failed_count' => 0,
            'status' => TaxBackfillRun::STATUS_RUNNING,
            'operator' => 'test-operator',
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function apply(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => TaxBackfillRun::MODE_APPLY,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaxBackfillRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
