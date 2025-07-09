<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TerminalAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Terminal Authentication Controller
 * 
 * PHASE 2 IMPLEMENTATION - Currently commented out
 * This controller will be implemented in Phase 2 for dynamic terminal authentication,
 * token refresh, and advanced terminal management features.
 * 
 * For Phase 1: Terminals use pre-generated Sanctum tokens from bulk upload
 */
class TerminalAuthController extends Controller
{
    /*
    // PHASE 2: Uncomment for advanced authentication features
    
    public function __construct(
        protected TerminalAuthService $terminalAuthService
    ) {}

    /**
     * Authenticate terminal using serial_number and api_key
     */
    /*
    public function authenticate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'serial_number' => 'required|string|max:255',
                'api_key' => 'required|string|min:32',
            ]);

            $authResult = $this->terminalAuthService->authenticateTerminal(
                $validated['serial_number'],
                $validated['api_key']
            );

            if (!$authResult) {
                return response()->json([
                    'error' => 'Authentication failed',
                    'message' => 'Invalid serial number or API key, or terminal is inactive'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'serial_number' => $authResult['terminal']->serial_number,
                    'tenant_id' => $authResult['terminal']->tenant_id,
                    'machine_number' => $authResult['terminal']->machine_number,
                    'access_token' => $authResult['access_token'],
                    'token_type' => $authResult['token_type'],
                    'expires_in' => $authResult['expires_in'],
                    'abilities' => $authResult['abilities'],
                    'terminal_config' => [
                        'supports_guest_count' => $authResult['terminal']->supports_guest_count,
                        'notifications_enabled' => $authResult['terminal']->notifications_enabled,
                        'heartbeat_threshold' => $authResult['terminal']->heartbeat_threshold,
                    ]
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid request data',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Authentication error',
                'message' => 'An unexpected error occurred during authentication'
            ], 500);
        }
    }

    /**
     * Refresh terminal token
     */
    /*
    public function refresh(Request $request): JsonResponse
    {
        try {
            $terminal = $request->user();
            
            if (!$terminal) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'No valid token provided'
                ], 401);
            }

            // Check if terminal is still active
            if (!$terminal->isActiveAndValid()) {
                return response()->json([
                    'error' => 'Terminal inactive',
                    'message' => 'Terminal is no longer active or has expired'
                ], 403);
            }

            // Generate new token
            $accessToken = $terminal->generateAccessToken();

            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $accessToken,
                    'token_type' => 'Bearer',
                    'expires_in' => config('sanctum.expiration', 1440) * 60,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Token refresh failed',
                'message' => 'Unable to refresh token'
            ], 500);
        }
    }

    /**
     * Get terminal information
     */
    /*
    public function me(Request $request): JsonResponse
    {
        try {
            $terminal = $request->user();
            
            if (!$terminal) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'No valid token provided'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'serial_number' => $terminal->serial_number,
                    'machine_number' => $terminal->machine_number,
                    'tenant_id' => $terminal->tenant_id,
                    'tenant_name' => $terminal->tenant->trade_name ?? null,
                    'status' => $terminal->status->name ?? 'Unknown',
                    'is_active' => $terminal->is_active,
                    'supports_guest_count' => $terminal->supports_guest_count,
                    'notifications_enabled' => $terminal->notifications_enabled,
                    'heartbeat_threshold' => $terminal->heartbeat_threshold,
                    'last_seen_at' => $terminal->last_seen_at,
                    'expires_at' => $terminal->expires_at,
                    'registered_at' => $terminal->registered_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to retrieve terminal information',
                'message' => 'An error occurred while fetching terminal data'
            ], 500);
        }
    }

    /**
     * Send heartbeat from terminal
     */
    /*
    public function heartbeat(Request $request): JsonResponse
    {
        try {
            $terminal = $request->user();
            
            if (!$terminal) {
                return response()->json([
                    'error' => 'Unauthenticated'
                ], 401);
            }

            // Check heartbeat ability
            if (!$terminal->tokenCan('heartbeat:send')) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            // Update last seen timestamp
            $terminal->update(['last_seen_at' => now()]);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Heartbeat received',
                    'server_time' => now()->toISOString(),
                    'next_heartbeat_due' => now()->addSeconds($terminal->heartbeat_threshold)->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Heartbeat failed',
                'message' => 'Unable to process heartbeat'
            ], 500);
        }
    }
    */
}