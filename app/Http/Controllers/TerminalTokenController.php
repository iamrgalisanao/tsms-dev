<?php

namespace App\Http\Controllers;

use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

class TerminalTokenController extends Controller
{
    public function revoke($terminalId)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);
            if (Schema::hasColumn('pos_terminals', 'is_revoked')) {
                $terminal->is_revoked = true;
                $terminal->save();
            }
            // Revoke all active Sanctum tokens for this terminal
            if (method_exists($terminal, 'tokens')) {
                $terminal->tokens()->delete();
            }
            \Log::info('Terminal API key and all tokens revoked', [
                'terminal_uid' => $terminal->terminal_uid,
                'user_id' => auth()->id()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('success', 'API Key and all tokens revoked for terminal ' . $terminal->terminal_uid);
        } catch (\Exception $e) {
            \Log::error('Error revoking terminal API key', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Error revoking API key: ' . $e->getMessage());
        }
    }
    public function index(Request $request)
    {
        $query = PosTerminal::with('tenant');
        
        // Apply filters
        if ($request->has('terminal_id') && !empty($request->terminal_id)) {
            $query->where('terminal_uid', 'like', '%' . $request->terminal_id . '%');
        }
        
        if ($request->has('status')) {
            switch ($request->status) {
                case 'active':
                    // If expires_at column exists, use it for filtering active terminals
                    if (Schema::hasColumn('pos_terminals', 'expires_at') && Schema::hasColumn('pos_terminals', 'is_revoked')) {
                        $query->where(function ($q) {
                            $q->whereNull('expires_at')
                              ->orWhere('expires_at', '>', now());
                        })->where('is_revoked', false);
                    } else {
                        // When columns don't exist, assume terminals with status active are active
                        $query->where('status', 'active');
                    }
                    break;
                case 'expired':
                    // Only apply if expires_at column exists
                    if (Schema::hasColumn('pos_terminals', 'expires_at') && Schema::hasColumn('pos_terminals', 'is_revoked')) {
                        $query->whereNotNull('expires_at')
                              ->where('expires_at', '<=', now())
                              ->where('is_revoked', false);
                    } else {
                        // When columns don't exist, assume terminals with status != active are expired
                        $query->where('status', '!=', 'active');
                    }
                    break;
                case 'revoked':
                    // Only apply if is_revoked column exists
                    if (Schema::hasColumn('pos_terminals', 'is_revoked')) {
                        $query->where('is_revoked', true);
                    } else {
                        // When column doesn't exist, assume no terminals are revoked
                        $query->where('id', -1); // This will return no results
                    }
                    break;
            }
        }
        
        // Get paginated results
        $terminals = $query->paginate(15);
        
        return view('dashboard.terminal-tokens', compact('terminals'));
    }
    
    public function regenerate($terminalId)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);
            // Generate a new API key (not JWT)
            $newApiKey = \Illuminate\Support\Str::random(64);
            $updateData = ['api_key' => $newApiKey];
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $updateData['expires_at'] = now()->addDays(30);
            }
            if (Schema::hasColumn('pos_terminals', 'is_revoked')) {
                $updateData['is_revoked'] = false;
            }
            $terminal->update($updateData);
            if (!Schema::hasColumn('pos_terminals', 'expires_at') && Schema::hasColumn('pos_terminals', 'status')) {
                $terminal->status = 'active';
                $terminal->save();
            }
            Log::info('Terminal API key regenerated', [
                'terminal_uid' => $terminal->terminal_uid,
                'user_id' => auth()->id()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('success', 'API Key successfully regenerated for terminal ' . $terminal->terminal_uid);
        } catch (\Exception $e) {
            Log::error('Error regenerating terminal API key', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Error regenerating API key: ' . $e->getMessage());
        }
    }
    
    // JWT logic is currently unused. To re-enable JWT, uncomment and update as needed.
    // /**
    //  * Generate JWT token for a terminal
    //  */
    // private function generateJWTToken(PosTerminal $terminal)
    // {
    //     // Create claims for the token
    //     $payload = [
    //         'sub' => $terminal->id,  // Subject (terminal ID)
    //         'iat' => now()->timestamp,         // Issued at
    //         'exp' => now()->addDays(30)->timestamp, // Expires at
    //         'tenant_id' => $terminal->tenant_id, // Include tenant ID for validation
    //         'terminal_uid' => $terminal->terminal_uid
    //     ];
    //     // Generate the token
    //     $token = JWTAuth::customClaims($payload)->fromUser($terminal);
    //     return $token;
    // }
}