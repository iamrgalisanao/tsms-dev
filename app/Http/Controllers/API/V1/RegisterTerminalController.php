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
            'terminal_uid'  => 'required|string|unique:pos_terminals,terminal_uid',
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
