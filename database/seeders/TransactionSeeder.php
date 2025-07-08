<?php

namespace Database\Seeders;

use App\Models\Transaction; // Use normalized model
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create([
            'code' => 'C-T1005',
            'name' => 'ABC Store #102',
            'status' => 'active'
        ]);

        // Create sample transactions using new schema
        $transaction = Transaction::create([
            // 'customer_code' => $tenant->code,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'transaction_timestamp' => Carbon::parse('2025-03-26T13:45:00Z'),
            'base_amount' => 12345.67,
            'payload_checksum' => hash('sha256', ''),
        ]);
        // Optionally add related data (adjustments, taxes, jobs, validations) here

        $transaction2 = Transaction::create([
            // 'customer_code' => $tenant->code,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'transaction_timestamp' => Carbon::now(),
            'base_amount' => 9682.50,
            'payload_checksum' => hash('sha256', ''),
        ]);

        $transaction3 = Transaction::create([
            // 'customer_code' => $tenant->code,
            'terminal_id' => 1,
            'hardware_id' => '7P589L2',
            'machine_number' => 6,
            'transaction_id' => Str::uuid(),
            'transaction_timestamp' => Carbon::now()->subHours(2),
            'base_amount' => -100.00, // Invalid negative amount
            'payload_checksum' => hash('sha256', ''),
        ]);
    }
}