<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ValidateTransaction
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Validate payload
            $payload = $request->json()->all();
            if (!$payload) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid JSON payload',
                    'timestamp' => now()->toISOString()
                ], 400);
            }

            return $next($request);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process transaction',
                'timestamp' => now()->toISOString()
            ], 500);
        }
    }
}