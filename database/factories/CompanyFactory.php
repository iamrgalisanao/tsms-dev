<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'customer_code' => $this->faker->unique()->regexify('[A-Z]{3}[0-9]{3}'),
            'company_name' => $this->faker->company,
            'tin' => $this->faker->unique()->numerify('###########'),
        ];
    }
}
