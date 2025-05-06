<?php


namespace Database\Seeders;

use App\Models\TransactionLog;
use App\Models\PosTerminal;
use Illuminate\Database\Seeder;

class TransactionLogSeeder extends Seeder
{
    public function run(): void
    {
        $terminals = PosTerminal::all();
        
        $statuses = ['success', 'failed', 'pending'];
        $types = ['payment', 'refund', 'void', 'settlement'];

        foreach ($terminals as $terminal) {
            // Create 50 random logs per terminal
            for ($i = 0; $i < 50; $i++) {
                TransactionLog::create([
                    'terminal_id' => $terminal->id,
                    'transaction_type' => $types[array_rand($types)],
                    'amount' => random_int(100, 10000) / 100, // Random amount between 1.00 and 100.00
                    'status' => $statuses[array_rand($statuses)],
                    'request_payload' => [
                        'merchant_id' => 'TEST_' . random_int(1000, 9999),
                        'reference_no' => 'REF_' . uniqid(),
                    ],
                    'response_payload' => [
                        'code' => '000',
                        'message' => 'Test transaction',
                        'trace_no' => 'TRACE_' . random_int(100000, 999999),
                    ],
                    'processed_at' => now()->subMinutes(random_int(1, 10080)), // Random time within last week
                    'created_at' => now()->subMinutes(random_int(1, 10080)),
                    'updated_at' => now()->subMinutes(random_int(1, 10080)),
                ]);
            }
        }
    }
}