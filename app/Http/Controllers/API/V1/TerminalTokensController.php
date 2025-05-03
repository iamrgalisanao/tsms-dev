<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TerminalToken;
use Illuminate\Http\Request;

class TerminalTokensController extends Controller
{
    public function index()
    {
        $tokens = TerminalToken::with(['terminal:id,terminal_id'])
            ->select([
                'id',
                'terminal_id',
                'is_active',
                'expires_at',
                'last_used_at',
                'created_at'
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $tokens
        ]);
    }

    public function regenerate($terminalId)
    {
        $token = TerminalToken::where('terminal_id', $terminalId)
            ->latest()
            ->first();

        if (!$token) {
            return response()->json([
                'error' => 'Terminal token not found'
            ], 404);
        }

        // Invalidate current token
        $token->update(['is_active' => false]);

        // Create new token
        $newToken = TerminalToken::create([
            'terminal_id' => $terminalId,
            'token' => \Str::random(64),
            'expires_at' => now()->addDays(30),
            'is_active' => true
        ]);

        return response()->json([
            'message' => 'Token regenerated successfully',
            'data' => $newToken
        ]);
    }
}
