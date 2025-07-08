<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\PosProvider;
use App\Models\ProviderStatistics;

class RegisterTerminalController extends Controller
{
    public function __invoke(Request $request)
    {
        /**
         * Handles the registration of a new POS terminal for a tenant.
         *
         * Validates the incoming request to ensure the tenant code exists and the terminal UID is unique.
         * Fetches the tenant record based on the provided tenant code.
         * Creates a new POS terminal associated with the tenant.
         * Attempts to request a JWT token from an external authentication service.
         *
         * @param  \Illuminate\Http\Request  $request  The incoming HTTP request containing terminal registration data.
         * @return \Illuminate\Http\JsonResponse       The response containing the result of the registration process.
         *
         * @throws \Illuminate\Validation\ValidationException If the request validation fails.
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the tenant is not found.
         */
        
        $validated = $request->validate([
            'tenant_code'   => 'required|string|exists:tenants,code',
            'serial_number'  => 'required|string|unique:pos_terminals,serial_number',
            'provider_code' => 'required|string|exists:pos_providers,code',
            'pos_type_id' => 'nullable|exists:pos_types,id',
            'integration_type_id' => 'nullable|exists:integration_types,id',
            'auth_type_id' => 'nullable|exists:auth_types,id',
            'status_id' => 'required|exists:terminal_statuses,id',
        ]);

        /**
         * Retrieves the Tenant model instance matching the provided tenant code from the validated request data.
         * Throws a ModelNotFoundException if no matching tenant is found.
         *
         * @var Tenant $tenant The tenant instance corresponding to the given tenant code.
         *
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the tenant with the specified code does not exist.
         */
        $tenant = Tenant::where('code', $validated['tenant_code'])->firstOrFail();
        $provider = PosProvider::where('code', $validated['provider_code'])->firstOrFail();

        /**
         * Creates a new POS terminal record in the database.
         *
         * The terminal is associated with the given tenant and uses the validated terminal UID.
         * The registration timestamp is set to the current time, and the status is set to 'active'.
         *
         * @var \App\Models\PosTerminal $terminal The newly created POS terminal instance.
         */
        $terminal = PosTerminal::create([
            'tenant_id'     => $tenant->id,
            'provider_id'   => $provider->id,
            'serial_number' => $validated['serial_number'],
            'pos_type_id'   => $validated['pos_type_id'] ?? null,
            'integration_type_id' => $validated['integration_type_id'] ?? null,
            'auth_type_id'  => $validated['auth_type_id'] ?? null,
            'status_id'     => $validated['status_id'],
            'registered_at' => now(),
            'enrolled_at'   => now(),
        ]);

        // Step 4: Request JWT token from external auth service
        try {
            $response = Http::post(config('services.auth.endpoint'), [
                'terminal_uid' => $terminal->serial_number,
                'secret_key'   => config('services.auth.secret_key'),
            ]);

            if ($response->successful()) {
                $token = $response->json()['token'] ?? null;

                if ($token) {
                    $terminal->update(['jwt_token' => $token]);
                } else {
                    Log::warning("JWT response missing token", $response->json());
                }
            } else {
                Log::error('Failed to get JWT token', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('JWT Token request failed', [
                'message' => $e->getMessage(),
            ]);
        }

        // Update provider statistics
        $this->updateProviderStatistics($provider->id);

        // Step 5: Return response
        return response()->json([
            'status'      => 'success',
            'terminal_id' => $terminal->id,
            'token'       => $terminal->jwt_token,
        ]);
    }
    
    /**
     * Update statistics for a provider
     */
    private function updateProviderStatistics($providerId)
    {
        try {
            $provider = PosProvider::find($providerId);
            if (!$provider) return;
            
            $today = now()->format('Y-m-d');
            
            // Calculate statistics
            $totalTerminals = PosTerminal::where('provider_id', $providerId)->count();
            $activeTerminals = PosTerminal::where('provider_id', $providerId)
                ->where('status', 'active')
                ->count();
            $newTerminalsToday = PosTerminal::where('provider_id', $providerId)
                ->whereDate('enrolled_at', $today)
                ->count();
                
            // Calculate growth rate (last 30 days vs previous 30 days)
            $thirtyDaysAgo = now()->subDays(30);
            $sixtyDaysAgo = now()->subDays(60);
            
            $last30Days = PosTerminal::where('provider_id', $providerId)
                ->where('enrolled_at', '>=', $thirtyDaysAgo)
                ->count();
                
            $previous30Days = PosTerminal::where('provider_id', $providerId)
                ->where('enrolled_at', '>=', $sixtyDaysAgo)
                ->where('enrolled_at', '<', $thirtyDaysAgo)
                ->count();
                
            $growthRate = 0;
            if ($previous30Days > 0) {
                $growthRate = (($last30Days - $previous30Days) / $previous30Days) * 100;
            } elseif ($last30Days > 0) {
                $growthRate = 100; // 100% growth if there were no terminals before
            }
            
            // Update or create statistics record
            ProviderStatistics::updateOrCreate(
                ['provider_id' => $providerId, 'date' => $today],
                [
                    'terminal_count' => $totalTerminals,
                    'active_terminal_count' => $activeTerminals,
                    'new_terminals_today' => $newTerminalsToday,
                    'growth_rate' => $growthRate
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to update provider statistics', [
                'provider_id' => $providerId,
                'error' => $e->getMessage()
            ]);
        }
    }
}