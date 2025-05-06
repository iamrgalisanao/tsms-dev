<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PosTerminal;
use App\Models\TerminalToken;
use Carbon\Carbon;

class TerminalTokenSeeder extends Seeder
{
    public function run()
    {
        // Get all terminals
        $terminals = PosTerminal::all();

        foreach ($terminals as $terminal) {
            // Create an active token
            TerminalToken::create([
                'terminal_id' => $terminal->id,
                'access_token' => bin2hex(random_bytes(32)),
                'issued_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addDays(30),
                'last_used_at' => Carbon::now()->subHours(rand(1, 24)),
                'is_revoked' => false
            ]);

            // Create an expired token
            TerminalToken::create([
                'terminal_id' => $terminal->id,
                'access_token' => bin2hex(random_bytes(32)),
                'issued_at' => Carbon::now()->subDays(60),
                'expires_at' => Carbon::now()->subDays(30),
                'last_used_at' => Carbon::now()->subDays(35),
                'is_revoked' => false
            ]);

            // Create a revoked token
            TerminalToken::create([
                'terminal_id' => $terminal->id,
                'access_token' => bin2hex(random_bytes(32)),
                'issued_at' => Carbon::now()->subDays(15),
                'expires_at' => Carbon::now()->addDays(15),
                'last_used_at' => Carbon::now()->subDays(10),
                'is_revoked' => true,
                'revoked_at' => Carbon::now()->subDays(5),
                'revoked_reason' => 'Security audit'
            ]);
        }
    }
}
