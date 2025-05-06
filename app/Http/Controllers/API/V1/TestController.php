<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
{
    /**
     * Test endpoint for circuit breaker verification
     * 
     * This endpoint can be configured to fail on demand based on query parameters:
     * - ?fail=true        - Forces the endpoint to return a 500 error
     * - ?delay=5000       - Adds a delay in milliseconds to simulate slow responses
     * - ?status=503       - Returns a specific HTTP status code
     * - ?probability=0.5  - Fails with the given probability (0-1)
     */
    public function testCircuitBreaker(Request $request): JsonResponse
    {
        // Get parameters with defaults
        $shouldFail = filter_var($request->query('fail', false), FILTER_VALIDATE_BOOLEAN);
        $delay = (int)$request->query('delay', 0);
        $statusCode = (int)$request->query('status', 500);
        $probability = (float)$request->query('probability', 1.0);

        // Log the request
        Log::info('Circuit breaker test endpoint called', [
            'params' => $request->query(),
            'service' => 'test_service'
        ]);

        // Apply delay if requested
        if ($delay > 0) {
            usleep($delay * 1000); // Convert to microseconds
        }

        // Determine if should fail
        $shouldFailRandom = (mt_rand(0, 100) / 100) < $probability;
        
        // Return error response if configured to fail
        if ($shouldFail && $shouldFailRandom) {
            Log::warning('Circuit breaker test endpoint failing (configured)', [
                'service' => 'test_service',
                'status' => $statusCode
            ]);
            
            return response()->json([
                'error' => 'Simulated failure for circuit breaker testing',
                'timestamp' => now()->toIso8601String()
            ], $statusCode);
        }

        // Return success response
        return response()->json([
            'status' => 'ok',
            'message' => 'Circuit breaker test endpoint working correctly',
            'timestamp' => now()->toIso8601String()
        ]);
    }
}
