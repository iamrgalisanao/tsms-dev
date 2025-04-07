<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;
use App\Models\PosTerminal;

class RegisterTerminalController extends Controller
{
    public function __invoke(Request $request)
    {
        // Step 1: Validate input
        $validated = $request->validate([
            'tenant_code'   => 'required|string|exists:tenants,code',
            'terminal_uid'  => 'required|string|unique:pos_terminals,terminal_uid',
        ]);

        // Step 2: Fetch tenant
        $tenant = Tenant::where('code', $validated['tenant_code'])->firstOrFail();

        // Step 3: Create the POS terminal
        $terminal = PosTerminal::create([
            'tenant_id'     => $tenant->id,
            'terminal_uid'  => $validated['terminal_uid'],
            'registered_at' => now(),
            'status'        => 'active',
        ]);

        // Step 4: Request JWT token from external auth service
        try {
            $response = Http::post(config('services.auth.endpoint'), [
                'terminal_uid' => $terminal->terminal_uid,
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

        // Step 5: Return response
        return response()->json([
            'status'      => 'success',
            'terminal_id' => $terminal->id,
            'token'       => $terminal->jwt_token,
        ]);
    }
}
