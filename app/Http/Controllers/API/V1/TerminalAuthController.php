<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\PosProvider;
use App\Models\ProviderStatistics;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class TerminalAuthController extends Controller
{
    public function authenticate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'serial_number' => ['required', 'string', 'max:255'],
                'api_key' => ['required', 'string'],
            ]);

            $terminal = PosTerminal::where('serial_number', $validated['serial_number'])
                ->where('api_key', $validated['api_key'])
                ->with('tenant:id,trade_name')
                ->first();

            if (! $terminal || ! $terminal->isActiveAndValid()) {
                return response()->json([
                    'error' => 'Authentication failed',
                    'message' => 'Invalid serial number or API key, or terminal is inactive',
                ], 401);
            }

            $accessToken = $terminal->generateAccessToken();
            $terminal->forceFill(['last_seen_at' => now()])->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'serial_number' => $terminal->serial_number,
                    'tenant_id' => $terminal->tenant_id,
                    'machine_number' => $terminal->machine_number,
                    'access_token' => $accessToken,
                    'token_type' => 'Bearer',
                    'expires_in' => config('sanctum.expiration') ? (int) config('sanctum.expiration') * 60 : null,
                    'abilities' => $terminal->getTokenAbilities(),
                    'terminal_config' => $this->terminalConfigPayload($terminal),
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Invalid request data',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Terminal authentication failed', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => 'Authentication error',
                'message' => 'An unexpected error occurred during authentication',
            ], 500);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        $terminal = $request->user();

        if (! $terminal instanceof PosTerminal) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'No valid terminal token provided',
            ], 401);
        }

        if (! $terminal->isActiveAndValid()) {
            return response()->json([
                'error' => 'Terminal inactive',
                'message' => 'Terminal is no longer active or has expired',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $terminal->generateAccessToken(),
                'token_type' => 'Bearer',
                'expires_in' => config('sanctum.expiration') ? (int) config('sanctum.expiration') * 60 : null,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $terminal = $request->user();

        if (! $terminal instanceof PosTerminal) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'No valid terminal token provided',
            ], 401);
        }

        $terminal->loadMissing('tenant:id,trade_name', 'status:id,name');

        return response()->json([
            'success' => true,
            'data' => [
                'serial_number' => $terminal->serial_number,
                'machine_number' => $terminal->machine_number,
                'tenant_id' => $terminal->tenant_id,
                'tenant_name' => $terminal->tenant->trade_name ?? null,
                'status' => $terminal->status->name ?? null,
                'is_active' => (bool) $terminal->is_active,
                'last_seen_at' => optional($terminal->last_seen_at)->toISOString(),
                'expires_at' => optional($terminal->expires_at)->toISOString(),
                'registered_at' => optional($terminal->registered_at)->toISOString(),
                'terminal_config' => $this->terminalConfigPayload($terminal),
            ],
        ]);
    }

    /**
     * POS heartbeat endpoint
     *
     * Auth: Sanctum Bearer token with ability 'heartbeat:send'.
     * Side effect: updates terminal.last_seen_at to server time.
     * Response includes server_time and next_heartbeat_due (server_time + heartbeat_threshold seconds).
     */
    public function heartbeat(Request $request): JsonResponse
    {
        try {
            $terminal = $request->user();

            if (!$terminal) {
                return response()->json([
                    'error' => 'Unauthenticated'
                ], 401);
            }

            // Ensure token has heartbeat ability when Sanctum abilities are enforced
            if (method_exists($terminal, 'tokenCan') && !$terminal->tokenCan('heartbeat:send')) {
                return response()->json([
                    'error' => 'Insufficient permissions'
                ], 403);
            }

            // Update liveness timestamp
            $terminal->last_seen_at = now();
            $terminal->save();

            $serverTime = Carbon::now();
            $threshold = (int)($terminal->heartbeat_threshold ?? 300); // default 5 minutes if unset
            $nextDue = (clone $serverTime)->addSeconds($threshold);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Heartbeat received',
                    'server_time' => $serverTime->toISOString(),
                    'next_heartbeat_due' => $nextDue->toISOString(),
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Heartbeat failed', [
                'error' => $e->getMessage(),
                'terminal_id' => isset($terminal) && $terminal instanceof PosTerminal ? $terminal->id : null,
            ]);
            return response()->json([
                'error' => 'Heartbeat failed',
                'message' => 'Unable to process heartbeat'
            ], 500);
        }
    }

    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_code' => 'required|exists:tenants,customer_code',
                'serial_number' => 'required|string|unique:pos_terminals,serial_number',
                'provider_name' => 'nullable|string', // Optional - will create or find provider
                'callback_url' => 'nullable|url',
                'machine_number' => 'nullable|string',
            ]);

            // Find tenant by customer_code
            $tenant = Tenant::where('customer_code', $validated['customer_code'])->firstOrFail();

            // Handle provider - create if doesn't exist
            $provider = $this->findOrCreateProvider($validated['provider_name'] ?? 'Default Provider');

            // Create the terminal
            $terminal = PosTerminal::create([
                'tenant_id' => $tenant->id,
                'provider_id' => $provider->id,
                'serial_number' => $validated['serial_number'],
                'machine_number' => $validated['machine_number'] ?? null,
                'callback_url' => $validated['callback_url'] ?? null,
                'status_id' => 1, // Active status
                'is_active' => true,
                'registered_at' => now(),
                'api_key' => \Illuminate\Support\Str::random(32), // Generate API key
            ]);

            // Generate Sanctum token
            $token = $terminal->createToken(
                'terminal-' . $terminal->serial_number,
                ['transaction:create', 'transaction:read', 'transaction:status', 'heartbeat:send']
            )->plainTextToken;

            // Update provider statistics
            $this->updateProviderStatistics($provider->id);

            return response()->json([
                'status' => 'success',
                'message' => 'Terminal registered successfully',
                'terminal_id' => $terminal->id,
                'serial_number' => $terminal->serial_number,
                'token' => $token,
                'provider' => $provider->name,
                'tenant' => $tenant->trade_name,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Find or create a POS provider
     */
    private function findOrCreateProvider($providerName)
    {
        // Try to find existing provider by name
        $provider = PosProvider::where('name', $providerName)->first();

        if (!$provider) {
            // Create new provider if doesn't exist
            $provider = PosProvider::create([
                'name' => $providerName,
                'contact_email' => 'support@' . strtolower(str_replace(' ', '', $providerName)) . '.com',
                'contact_phone' => 'N/A',
                'status' => 'active',
            ]);
        }

        return $provider;
    }
    private function updateProviderStatistics($providerId)
    {
        try {
            $today = Carbon::now()->format('Y-m-d');

            // Calculate current statistics
            $totalTerminals = PosTerminal::where('provider_id', $providerId)->count();
            $activeTerminals = PosTerminal::where('provider_id', $providerId)
                ->where('status', 'active')
                ->count();
            $inactiveTerminals = $totalTerminals - $activeTerminals;

            // Calculate new enrollments today
            $newEnrollments = PosTerminal::where('provider_id', $providerId)
                ->whereDate('enrolled_at', $today)
                ->count();

            // Update the statistics record for today
            ProviderStatistics::updateOrCreate(
                ['provider_id' => $providerId, 'date' => $today],
                [
                    'terminal_count' => $totalTerminals,
                    'active_terminal_count' => $activeTerminals,
                    'inactive_terminal_count' => $inactiveTerminals,
                    'new_enrollments' => $newEnrollments,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to update provider statistics', [
                'provider_id' => $providerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function terminalConfigPayload(PosTerminal $terminal): array
    {
        return [
            'supports_guest_count' => (bool) $terminal->supports_guest_count,
            'notifications_enabled' => (bool) $terminal->notifications_enabled,
            'heartbeat_threshold' => (int) ($terminal->heartbeat_threshold ?? 300),
        ];
    }
}
