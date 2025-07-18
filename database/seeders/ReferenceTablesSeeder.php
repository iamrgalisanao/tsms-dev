<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceTablesSeeder extends Seeder
{
    public function run(): void
    {
        // Seed pos_types
        DB::table('pos_types')->insertOrIgnore([
            ['id' => 1, 'name' => 'Default POS Type']
        ]);

        // Seed integration_types
        DB::table('integration_types')->insertOrIgnore([
            ['id' => 1, 'name' => 'Default Integration']
        ]);

        // Seed auth_types
        DB::table('auth_types')->insertOrIgnore([
            ['id' => 1, 'name' => 'Default Auth']
        ]);

        // Seed statuses
        DB::table('statuses')->insertOrIgnore([
            ['id' => 1, 'name' => 'Active']
        ]);
    }
}