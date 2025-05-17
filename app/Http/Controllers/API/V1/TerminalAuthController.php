<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\PosProvider;
use App\Models\ProviderStatistics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

class TerminalAuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'tenant_code' => 'required|exists:tenants,code',
                'terminal_uid' => 'required|string|unique:pos_terminals,terminal_uid',
                'provider_code' => 'required|exists:pos_providers,code',
            ]);

            // Find the tenant and provider
            $tenant = Tenant::where('code', $validated['tenant_code'])->firstOrFail();
            $provider = PosProvider::where('code', $validated['provider_code'])->firstOrFail();

            // Create the terminal
            $terminal = PosTerminal::create([
                'tenant_id' => $tenant->id,
                'provider_id' => $provider->id,
                'terminal_uid' => $validated['terminal_uid'],
                'registered_at' => now(),
                'enrolled_at' => now(),
                'status' => 'active',
            ]);

            // Generate JWT token
            $token = JWTAuth::fromUser($terminal);

            // Update provider statistics for today
            $this->updateProviderStatistics($provider->id);

            return response()->json([
                'status' => 'success',
                'token' => $token,
                'terminal_id' => $terminal->id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update provider statistics after terminal registration
     */
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

