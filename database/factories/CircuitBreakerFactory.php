<?php


namespace Database\Factories;

use App\Models\CircuitBreaker;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CircuitBreakerFactory extends Factory
{
    protected $model = CircuitBreaker::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->unique()->word . '_service',
            'status' => 'CLOSED',
            'trip_count' => 0,
            'failure_threshold' => 5,
            'reset_timeout' => 60,
            'last_failure_at' => null,
            'cooldown_until' => null
        ];
    }
}