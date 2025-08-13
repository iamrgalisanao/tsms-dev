<?php

namespace App\Http\Controllers;

use App\Models\PosTerminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class TerminalTokenController extends Controller
{
    public function revoke($terminalId)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);
            
            // Mark terminal as revoked using status_id (3 = revoked status)
            $terminal->status_id = 3; // 'revoked' status in terminal_statuses table
            $terminal->is_active = false; // Also set is_active to false
            $terminal->save();
            
            // Revoke all active Sanctum tokens for this terminal
            $tokenCount = 0;
            if (method_exists($terminal, 'tokens')) {
                $tokenCount = $terminal->tokens()->count();
                $terminal->tokens()->delete();
            }
            
            Log::info('Terminal Bearer tokens revoked', [
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'tokens_revoked' => $tokenCount,
                'user_id' => auth()->id()
            ]);
            
            return redirect()
                ->route('terminal-tokens')
                ->with('success', "All Bearer tokens ({$tokenCount}) revoked for terminal " . ($terminal->terminal_uid ?? $terminal->serial_number));
        } catch (\Exception $e) {
            Log::error('Error revoking terminal Bearer tokens', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Error revoking Bearer tokens: ' . $e->getMessage());
        }
    }
    public function index(Request $request)
    {
        $query = PosTerminal::with(['tenant', 'tokens' => function($query) {
            $query->select('tokenable_id', 'name', 'created_at', 'last_used_at')
                  ->where('tokenable_type', 'App\Models\PosTerminal');
        }]);
        
        // Apply filters
        if ($request->has('terminal_id') && !empty($request->terminal_id)) {
            $query->where(function($q) use ($request) {
                $q->where('terminal_uid', 'like', '%' . $request->terminal_id . '%')
                  ->orWhere('serial_number', 'like', '%' . $request->terminal_id . '%');
            });
        }
        
        if ($request->has('status')) {
            switch ($request->status) {
                case 'active':
                    // Filter active terminals using status_id = 1 and is_active = true
                    $query->where('status_id', 1)
                          ->where('is_active', true);
                    break;
                case 'expired':
                    // Filter expired terminals using status_id = 4
                    $query->where('status_id', 4);
                    break;
                case 'revoked':
                    // Filter revoked terminals using status_id = 3
                    $query->where('status_id', 3);
                    break;
                case 'inactive':
                    // Filter inactive terminals using status_id = 2 or is_active = false
                    $query->where(function($q) {
                        $q->where('status_id', 2)
                          ->orWhere('is_active', false);
                    });
                    break;
                case 'has_tokens':
                    // Filter terminals that have active Bearer tokens
                    $query->whereHas('tokens');
                    break;
                case 'no_tokens':
                    // Filter terminals that have no Bearer tokens
                    $query->whereDoesntHave('tokens');
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
            
            // Update terminal status and expiration
            $updateData = [];
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $updateData['expires_at'] = now()->addDays(30);
            }
            
            // Set terminal to active status (status_id = 1) and is_active = true
            $updateData['status_id'] = 1; // Active status
            $updateData['is_active'] = true;
            
            if (!empty($updateData)) {
                $terminal->update($updateData);
            }
            
            // Revoke existing Sanctum tokens
            if (method_exists($terminal, 'tokens')) {
                $terminal->tokens()->delete();
            }
            
            // Generate new Bearer token using Sanctum
            $bearerToken = $this->generateBearerToken($terminal);
            
            Log::info('Terminal Bearer token regenerated', [
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'user_id' => auth()->id(),
                'token_name' => 'terminal-' . ($terminal->serial_number ?? $terminal->terminal_uid)
            ]);
            
            return redirect()
                ->route('terminal-tokens')
                ->with('success', 'Bearer token regenerated for terminal ' . ($terminal->terminal_uid ?? $terminal->serial_number))
                ->with('bearer_token', $bearerToken); // Token will be shown once for security
                
        } catch (\Exception $e) {
            Log::error('Error regenerating terminal Bearer token', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Error regenerating Bearer token: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate Bearer token for a terminal using Sanctum
     */
    private function generateBearerToken(PosTerminal $terminal): string
    {
        // Define token abilities based on terminal requirements
        $abilities = [
            'transaction:create',
            'transaction:read',
            'heartbeat:send',
        ];
        
        // Generate token name
        $tokenName = 'terminal-' . ($terminal->serial_number ?? $terminal->terminal_uid ?? $terminal->id);
        
        // Create Sanctum token
        $token = $terminal->createToken($tokenName, $abilities);
        
        return $token->plainTextToken;
    }
    
    /**
     * Generate Bearer token via API endpoint (for programmatic access)
     */
    public function generateToken($terminalId)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);
            
            // Check if terminal is active and not revoked
            // Use status_id = 3 for revoked status instead of is_revoked field
            if ($terminal->status_id === 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot generate token for revoked terminal'
                ], 403);
            }
            
            // Check if terminal is active (status_id = 1 and is_active = true)
            if ($terminal->status_id !== 1 || !$terminal->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot generate token for inactive terminal'
                ], 403);
            }
            
            // Revoke existing tokens first
            if (method_exists($terminal, 'tokens')) {
                $terminal->tokens()->delete();
            }
            
            // Generate new Bearer token
            $bearerToken = $this->generateBearerToken($terminal);
            
            Log::info('Bearer token generated via API', [
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'user_id' => auth()->id()
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $bearerToken,
                    'token_type' => 'Bearer',
                    'terminal_id' => $terminal->id,
                    'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                    'expires_in' => config('sanctum.expiration', 1440) * 60, // Convert minutes to seconds
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating Bearer token via API', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error generating Bearer token: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * List all tokens for a specific terminal
     */
    public function listTokens($terminalId)
    {
        try {
            $terminal = PosTerminal::with('tokens')->findOrFail($terminalId);
            
            $tokens = $terminal->tokens->map(function($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'terminal_id' => $terminal->id,
                    'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                    'tokens' => $tokens
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving tokens: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Generate Bearer tokens for all active terminals
     */
    public function generateTokensForAllTerminals()
    {
        try {
            // Get all active terminals using the correct database schema
            $query = PosTerminal::query();
            
            // Filter active terminals using status_id (not 'status' field)
            // status_id = 1 is 'active', status_id = 3 is 'revoked'
            $query->where('status_id', 1) // Active status
                  ->where('is_active', true); // Also check is_active boolean field
            
            $terminals = $query->get();
            $results = [];
            $successCount = 0;
            $failureCount = 0;
            
            foreach ($terminals as $terminal) {
                try {
                    // Revoke existing tokens
                    if (method_exists($terminal, 'tokens')) {
                        $terminal->tokens()->delete();
                    }
                    
                    // Generate new token
                    $bearerToken = $this->generateBearerToken($terminal);
                    
                    $results[] = [
                        'terminal_id' => $terminal->id,
                        'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                        'status' => 'success',
                        'token' => $bearerToken, // Include token in response (be careful with security)
                    ];
                    $successCount++;
                    
                } catch (\Exception $e) {
                    $results[] = [
                        'terminal_id' => $terminal->id,
                        'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                    $failureCount++;
                }
            }
            
            Log::info('Bulk Bearer token generation completed', [
                'total_terminals' => count($terminals),
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'user_id' => auth()->id()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Tokens generated: {$successCount} successful, {$failureCount} failed",
                'data' => [
                    'total_terminals' => count($terminals),
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                    'results' => $results
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in bulk token generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error generating bulk tokens: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Introspect a token to validate and retrieve its claims.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function introspectToken(Request $request)
    {
        $raw = $request->bearerToken();
        if (!$raw) {
            return $this->invalidTokenResponse('missing');
        }

        // Sanctum tokens have the form id|plaintexttoken
        if (!str_contains($raw, '|')) {
            Log::warning('Token introspection: malformed token format');
            return $this->invalidTokenResponse('malformed');
        }

        [$id, $plain] = explode('|', $raw, 2);
        if (!ctype_digit($id) || empty($plain)) {
            Log::warning('Token introspection: invalid id or empty plain segment', ['token_id' => $id]);
            return $this->invalidTokenResponse('malformed');
        }

        $hashed = hash('sha256', $plain);

        // Query personal access token
        $pat = PersonalAccessToken::query()
            ->where('id', $id)
            ->where('token', $hashed)
            ->first();

        if (!$pat) {
            Log::info('Token introspection: token not found or hash mismatch', ['token_id' => $id]);
            return $this->invalidTokenResponse('not_found');
        }

        // Ensure tokenable is a POS terminal
        if ($pat->tokenable_type !== PosTerminal::class) {
            Log::warning('Token introspection: tokenable type mismatch', ['token_id' => $id, 'type' => $pat->tokenable_type]);
            return $this->invalidTokenResponse('wrong_type');
        }

        $terminal = PosTerminal::find($pat->tokenable_id);
        if (!$terminal) {
            Log::warning('Token introspection: terminal missing', ['token_id' => $id]);
            return $this->invalidTokenResponse('orphan');
        }

        $expired = $pat->expires_at && now()->gte($pat->expires_at);
        $revoked = property_exists($pat, 'is_revoked') ? ($pat->is_revoked ?? false) : false;
        $inactive = !$terminal->isActiveAndValid();

        if ($expired || $revoked || $inactive) {
            Log::info('Token introspection: inactive token', [
                'token_id' => $id,
                'expired' => $expired,
                'revoked' => $revoked,
                'inactive_terminal' => $inactive
            ]);
            return $this->invalidTokenResponse('inactive');
        }

        // Parse abilities JSON (Sanctum stores as JSON in abilities attribute)
        $abilities = $pat->abilities ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'active' => true,
                'terminal_id' => $terminal->id,
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'tenant_id' => $terminal->tenant_id,
                'provider_id' => $terminal->provider_id ?? null,
                'abilities' => $abilities,
                'expires_at' => $pat->expires_at,
                'last_used_at' => $pat->last_used_at,
                'issued_at' => $pat->created_at,
            ]
        ]);
    }

    private function invalidTokenResponse(string $reason)
    {
        // Always collapse to same outward response (avoid enumeration), include code per contract
        return response()->json([
            'success' => false,
            'code' => 'invalid_token',
            'message' => 'Invalid or expired token',
        ], 401);
    }
}