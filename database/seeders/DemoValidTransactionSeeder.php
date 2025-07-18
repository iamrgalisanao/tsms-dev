<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class DemoValidTransactionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('transactions')->insert([
           
            [
                'tenant_id' => 1,
                'terminal_id' => 65, // Dormitos
                'transaction_id' => 'TXDEMO009',
                'hardware_id' => 'HW004',
                'transaction_timestamp' => Carbon::now(),
                'processed_at' => Carbon::now(),
                'base_amount' => 400.00,
                'customer_code' => 'CUST004',
                'payload_checksum' => 'demo101checksum',
                'validation_status' => 'VALID',
                'submission_uuid' => 'demo-uuid-004',
                'submission_timestamp' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);
    }
}