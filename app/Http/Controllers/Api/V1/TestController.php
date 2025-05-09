<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;

class TestController extends Controller
{
    public function testEndpoint(): JsonResponse
    {
        return response()->json(['message' => 'Test endpoint successful']);
    }

    public function testCircuit(Request $request): JsonResponse
    {
        if ($request->input('should_fail', false)) {
            abort(500, 'Simulated failure for circuit breaker test');
        }

        return response()->json(['message' => 'Test circuit successful']);
    }

    public function testCircuitBreaker(): JsonResponse
    {
        // Simulate a service failure
        if (rand(1, 100) <= 70) { // 70% chance of failure
            throw new \Exception('Service temporarily unavailable');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Service is healthy'
        ]);
    }

    public function resetTestCircuit(Request $request): JsonResponse
    {
        // Get tenant ID from request or use default for testing
        $tenantId = $request->header('X-Tenant-ID', 1);
        
        // Reset Redis failure count
        $redisKey = "circuit_breaker:{$tenantId}:test_service";
        Redis::del("{$redisKey}:failure_count");
        Redis::del("{$redisKey}:success_count");
        Redis::del("{$redisKey}:last_failure");
        
        // Reset circuit breaker state
        $circuitBreaker = CircuitBreaker::where('tenant_id', $tenantId)
            ->where('name', 'test_service')
            ->first();
            
        if ($circuitBreaker) {
            $circuitBreaker->status = CircuitBreaker::STATUS_CLOSED;
            $circuitBreaker->trip_count = 0;
            $circuitBreaker->cooldown_until = now();
            $circuitBreaker->save();
        }

        return response()->json(['message' => 'Test circuit reset successful']);
    }
}
