<?php


namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company,
            'code' => $this->faker->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ];
    }
}