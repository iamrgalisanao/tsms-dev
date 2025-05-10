<?php

namespace Database\Factories;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityEventFactory extends Factory
{
    protected $model = SecurityEvent::class;

    public function definition()
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_type' => $this->faker->randomElement([
                'login_failure',
                'suspicious_activity',
                'rate_limit_breach',
                'circuit_breaker_trip',
                'unauthorized_access',
                'permission_violation'
            ]),
            'severity' => $this->faker->randomElement(['info', 'warning', 'critical']),
            'user_id' => User::factory(),
            'source_ip' => $this->faker->ipv4,
            'context' => ['data' => $this->faker->sentence],
            'event_timestamp' => now(),
        ];
    }
}