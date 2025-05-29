<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Store;

class StoreHoursSeeder extends Seeder
{
    public function run()
    {
        // Default store hours for all days
        $defaultHours = [
            'monday' => ['open' => '06:00', 'close' => '22:00'],
            'tuesday' => ['open' => '06:00', 'close' => '22:00'],
            'wednesday' => ['open' => '06:00', 'close' => '22:00'],
            'thursday' => ['open' => '06:00', 'close' => '22:00'],
            'friday' => ['open' => '06:00', 'close' => '22:00'],
            'saturday' => ['open' => '06:00', 'close' => '22:00'],
            'sunday' => ['open' => '06:00', 'close' => '22:00'],
        ];

        // Get all stores
        $stores = Store::all();

        foreach ($stores as $store) {
            DB::table('store_hours')->updateOrInsert(
                ['store_id' => $store->id],
                [
                    'operating_hours' => json_encode($defaultHours),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
