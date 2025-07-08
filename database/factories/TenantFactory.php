<?php


namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'trade_name' => $this->faker->company,
            'location_type' => $this->faker->randomElement(['Kiosk', 'Inline']),
            'location' => $this->faker->address,
            'unit_no' => $this->faker->optional()->bothify('Unit ##'),
            'floor_area' => $this->faker->optional()->randomFloat(2, 10, 500),
            'status' => 'Operational',
            'category' => $this->faker->optional()->randomElement(['Services', 'F&B', 'Retail']),
            'zone' => $this->faker->optional()->word,
        ];
    }
}