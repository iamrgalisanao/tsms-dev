<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TerminalToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TerminalTokensController extends Controller
{
    public function index(Request $request)
    {
        try {
            Log::info('Fetching terminal tokens');
            
            $query = TerminalToken::with(['terminal:id,terminal_uid'])
                ->select([
                    'id',
                    'terminal_id',
                    'access_token',
                    'issued_at',
                    'expires_at',
                    'is_revoked',
                    'revoked_at',
                    'revoked_reason',
                    'last_used_at',
                    'created_at',
                    'updated_at'
                ]);

            if ($request->has('terminal_id') && $request->terminal_id !== '') {
                $query->where('terminal_id', $request->terminal_id);
            }

            $result = $query->get();
            Log::info('Terminal tokens fetched', ['count' => $result->count()]);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('Failed to fetch terminal tokens', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch terminal tokens',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function regenerate($terminalId)
    {
        try {
            // This would normally interact with your database
            // For now we'll return simulated data
            $tokenData = [
                'terminal_id' => $terminalId,
                'access_token' => 'T7QQt3wAehKbLcJCwAwqjfWeMnFM7uSZb2y-7cEE8XE4oFv0+TIId9vpcbo=',
                'expires_at' => '2025-06-10 13:57:30.000000',
            ];
            
            // For API requests, return JSON
            if (request()->expectsJson()) {
                return response()->json([
                    'message' => 'Token regenerated successfully',
                    'data' => $tokenData
                ]);
            }
            
            // For web requests, redirect with session data
            return redirect()->back()->with('success', 'Token regenerated successfully')
                                    ->with('token_info', $tokenData);
        } catch (\Exception $e) {
            Log::error('Failed to regenerate token', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage()
            ]);
            
            if (request()->expectsJson()) {
                return response()->json([
                    'message' => 'Failed to regenerate token',
                    'error' => $e->getMessage()
                ], 500);
            }
            
            return redirect()->back()->with('error', 'Failed to regenerate token');
        }
    }

    public function show($id)
    {
        try {
            Log::info('Fetching terminal token details', ['token_id' => $id]);
            
            $token = TerminalToken::with(['terminal:id,terminal_uid'])
                ->findOrFail($id);
                
            Log::info('Terminal token details fetched', ['token_id' => $id]);
            
            return response()->json($token);
            
        } catch (\Exception $e) {
            Log::error('Failed to fetch terminal token details', [
                'token_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch terminal token details',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function revoke($id)
    {
        try {
            Log::info('Revoking terminal token', ['token_id' => $id]);
            
            $token = TerminalToken::findOrFail($id);
            
            if ($token->is_revoked) {
                return response()->json([
                    'message' => 'Token is already revoked'
                ]);
            }
            
            $token->update([
                'is_revoked' => true,
                'revoked_at' => now(),
                'revoked_reason' => 'Manually revoked via API'
            ]);
            
            Log::info('Terminal token revoked successfully', ['token_id' => $id]);
            
            return response()->json([
                'message' => 'Token revoked successfully',
                'data' => $token
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to revoke terminal token', [
                'token_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to revoke terminal token',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function verify(Request $request)
    {
        try {
            Log::info('Verifying terminal token');
            
            $token = TerminalToken::where('access_token', $request->token)
                ->where('is_revoked', false)
                ->where('expires_at', '>', now())
                ->first();
                
            if (!$token) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Invalid or expired token'
                ], 401);
            }
            
            $token->update([
                'last_used_at' => now()
            ]);
            
            Log::info('Terminal token verified successfully', ['token_id' => $token->id]);
            
            return response()->json([
                'valid' => true,
                'terminal_id' => $token->terminal_id
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to verify terminal token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to verify token',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}