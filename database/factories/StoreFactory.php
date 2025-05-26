<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Store::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        // Get a random tenant or create one if none exists
        $tenant = Tenant::inRandomOrder()->first() ?: Tenant::factory()->create();
        
        return [
            'tenant_id' => $tenant->id,
            'name' => $this->faker->company,
            'address' => $this->faker->streetAddress,
            'city' => $this->faker->city,
            'state' => $this->faker->state,
            'postal_code' => $this->faker->postcode,
            'phone' => $this->faker->phoneNumber,
            'email' => $this->faker->email,
            'operating_hours' => [
                'monday' => ['open' => '09:00:00', 'close' => '18:00:00'],
                'tuesday' => ['open' => '09:00:00', 'close' => '18:00:00'],
                'wednesday' => ['open' => '09:00:00', 'close' => '18:00:00'],
                'thursday' => ['open' => '09:00:00', 'close' => '18:00:00'],
                'friday' => ['open' => '09:00:00', 'close' => '18:00:00'],
                'saturday' => ['open' => '10:00:00', 'close' => '17:00:00'],
                'sunday' => ['open' => '10:00:00', 'close' => '16:00:00'],
            ],
            'status' => 'active',
            'allows_service_charge' => $this->faker->boolean(70),
            'tax_exempt' => $this->faker->boolean(10),
            'max_daily_sales' => $this->faker->randomFloat(2, 10000, 100000),
            'max_transaction_amount' => $this->faker->randomFloat(2, 1000, 5000)
        ];
    }
    
    /**
     * Indicate that the store is active
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function active()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'active',
            ];
        });
    }
    
    /**
     * Indicate that the store allows service charges
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function allowsServiceCharge()
    {
        return $this->state(function (array $attributes) {
            return [
                'allows_service_charge' => true,
            ];
        });
    }
    
    /**
     * Indicate that the store is tax exempt
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function taxExempt()
    {
        return $this->state(function (array $attributes) {
            return [
                'tax_exempt' => true,
            ];
        });
    }
}