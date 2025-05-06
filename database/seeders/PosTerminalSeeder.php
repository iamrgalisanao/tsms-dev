<?php

namespace Database\Seeders;

use App\Models\PosTerminal;
use Illuminate\Database\Seeder;

class PosTerminalSeeder extends Seeder
{
    public function run(): void
    {
        $terminals = [
            [
                'tenant_id' => 1,
                'terminal_uid' => 'TERM001',
                'status' => 'active',
                'is_sandbox' => false,
                'webhook_url' => 'https://api.example.com/webhook/terminal1',
                'max_retries' => 3,
                'retry_interval_sec' => 60,
                'retry_enabled' => true,
                'jwt_token' => null,
                'registered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 1,
                'terminal_uid' => 'TERM002',
                'status' => 'active',
                'is_sandbox' => false,
                'webhook_url' => 'https://api.example.com/webhook/terminal2',
                'max_retries' => 3,
                'retry_interval_sec' => 60,
                'retry_enabled' => true,
                'jwt_token' => null,
                'registered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 1,
                'terminal_uid' => 'TERM003',
                'status' => 'active',
                'is_sandbox' => true,
                'webhook_url' => 'https://api.example.com/webhook/terminal3',
                'max_retries' => 3,
                'retry_interval_sec' => 60,
                'retry_enabled' => true,
                'jwt_token' => null,
                'registered_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($terminals as $terminal) {
            PosTerminal::create($terminal);
        }
    }
}