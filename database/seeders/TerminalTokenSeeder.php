<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TerminalToken;
use App\Models\PosTerminal;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TerminalTokenSeeder extends Seeder
{
    public function run()
    {
        // First, make sure we have some terminals to work with
        $terminal1 = PosTerminal::firstOrCreate(
            ['terminal_uid' => 'TERM001'],
            [
                'tenant_id' => 1, // Added tenant_id
                'status' => 'active',
                'is_sandbox' => false,
                'retry_enabled' => true,
                'max_retries' => 3,
                'retry_interval_sec' => 300
            ]
        );

        $terminal2 = PosTerminal::firstOrCreate(
            ['terminal_uid' => 'TERM002'],
            [
                'tenant_id' => 1, // Added tenant_id
                'status' => 'active',
                'is_sandbox' => false,
                'retry_enabled' => true,
                'max_retries' => 3,
                'retry_interval_sec' => 300
            ]
        );

        // Create different token scenarios
        // 1. Active token
        TerminalToken::create([
            'terminal_id' => $terminal1->id,
            'access_token' => Str::random(64),
            'issued_at' => now(),
            'expires_at' => now()->addDays(30),
            'is_revoked' => false,
            'last_used_at' => now()->subHours(2)
        ]);

        // 2. Expired token
        TerminalToken::create([
            'terminal_id' => $terminal1->id,
            'access_token' => Str::random(64),
            'issued_at' => now()->subDays(40),
            'expires_at' => now()->subDays(10),
            'is_revoked' => false,
            'last_used_at' => now()->subDays(15)
        ]);

        // 3. Revoked token
        TerminalToken::create([
            'terminal_id' => $terminal2->id,
            'access_token' => Str::random(64),
            'issued_at' => now()->subDays(5),
            'expires_at' => now()->addDays(25),
            'is_revoked' => true,
            'revoked_at' => now()->subDays(2),
            'revoked_reason' => 'Security breach detected',
            'last_used_at' => now()->subDays(3)
        ]);

        // 4. Never used token
        TerminalToken::create([
            'terminal_id' => $terminal2->id,
            'access_token' => Str::random(64),
            'issued_at' => now(),
            'expires_at' => now()->addDays(30),
            'is_revoked' => false,
            'last_used_at' => null
        ]);
    }
}