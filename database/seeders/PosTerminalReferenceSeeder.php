<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosTerminalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        // Seed pos_types
        DB::table('pos_types')->updateOrInsert(['id' => 1], [
            'id' => 1,
            'name' => 'Default POS Type',
        ]);

        // Seed integration_types
        DB::table('integration_types')->updateOrInsert(['id' => 1], [
            'id' => 1,
            'name' => 'Default Integration',
        ]);

        // Seed auth_types
        DB::table('auth_types')->updateOrInsert(['id' => 1], [
            'id' => 1,
            'name' => 'API Key',
        ]);

        // Seed terminal_statuses
        DB::table('terminal_statuses')->updateOrInsert(['id' => 1], [
            'id' => 1,
            'name' => 'Active',
        ]);
    }
}
