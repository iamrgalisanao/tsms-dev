<?php

namespace App\Http\Controllers;

use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

class TerminalTokenController extends Controller
{
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
            
            // Generate a new JWT token
            $token = $this->generateJWTToken($terminal);
            
            // Update the token in the database
            $updateData = ['jwt_token' => $token];
            
            // Check if expires_at column exists before setting it
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $updateData['expires_at'] = now()->addDays(30);
            }
            
            // Check if is_revoked column exists before setting it
            if (Schema::hasColumn('pos_terminals', 'is_revoked')) {
                $updateData['is_revoked'] = false;
            }
            
            // Update just the columns that exist
            $terminal->update($updateData);
            
            // Set status to active if no expires_at column
            if (!Schema::hasColumn('pos_terminals', 'expires_at') && Schema::hasColumn('pos_terminals', 'status')) {
                $terminal->status = 'active';
                $terminal->save();
            }
            
            Log::info('Terminal token regenerated', [
                'terminal_uid' => $terminal->terminal_uid,
                'user_id' => auth()->id()
            ]);
            
            return redirect()
                ->route('terminal-tokens')
                ->with('success', 'Token successfully regenerated for terminal ' . $terminal->terminal_uid);
                
        } catch (\Exception $e) {
            Log::error('Error regenerating terminal token', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Error regenerating token: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate JWT token for a terminal
     */
    private function generateJWTToken(PosTerminal $terminal)
    {
        // Create claims for the token
        $payload = [
            'sub' => $terminal->id,  // Subject (terminal ID)
            'iat' => now()->timestamp,         // Issued at
            'exp' => now()->addDays(30)->timestamp, // Expires at
            'tenant_id' => $terminal->tenant_id, // Include tenant ID for validation
            'terminal_uid' => $terminal->terminal_uid
        ];
        
        // Generate the token
        $token = JWTAuth::customClaims($payload)->fromUser($terminal);
        
        return $token;
    }
}