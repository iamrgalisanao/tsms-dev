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
use Carbon\Carbon;

class TerminalAuthController extends Controller
{
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
}