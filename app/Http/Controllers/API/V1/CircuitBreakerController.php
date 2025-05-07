<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\CircuitBreaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class CircuitBreakerController extends Controller
{
    /**
     * Get current states of all circuit breakers
     */
    public function getStates(Request $request): JsonResponse
    {
        $query = CircuitBreaker::query();
        
        // Filter by tenant if provided
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }
        
        // Filter by service if provided
        if ($request->has('service')) {
            $query->where('name', $request->service);
        }

        $circuitBreakers = $query->get()->map(function ($breaker) {
            return [
                'tenant_id' => $breaker->tenant_id,
                'name' => $breaker->name,
                'status' => $breaker->status,
                'trip_count' => $breaker->trip_count,
                'cooldown_until' => $breaker->cooldown_until,
                'last_updated' => $breaker->updated_at
            ];
        });

        return response()->json($circuitBreakers);
    }

    /**
     * Get metrics for specified circuit breaker
     */
    public function getMetrics(Request $request): JsonResponse
    {
        $tenantId = $request->get('tenant_id', 1);
        $service = $request->get('service');

        if (!$service) {
            return response()->json(['error' => 'Service name is required'], 400);
        }

        $redisKey = "circuit_breaker:{$tenantId}:{$service}";
        
        // Get metrics from Redis
        $metrics = [
            'timestamps' => [],
            'failure_rates' => [],
            'response_times' => []
        ];

        // Get last hour of metrics (stored in 5-minute intervals)
        for ($i = 0; $i < 12; $i++) {
            $timestamp = now()->subMinutes($i * 5);
            $timeKey = $timestamp->format('Y-m-d H:i');
            
            $metrics['timestamps'][] = $timeKey;
            $metrics['failure_rates'][] = (float) Redis::get("{$redisKey}:failure_rate:{$timeKey}") ?? 0;
            $metrics['response_times'][] = (float) Redis::get("{$redisKey}:response_time:{$timeKey}") ?? 0;
        }

        // Reverse arrays to show oldest first
        $metrics['timestamps'] = array_reverse($metrics['timestamps']);
        $metrics['failure_rates'] = array_reverse($metrics['failure_rates']);
        $metrics['response_times'] = array_reverse($metrics['response_times']);

        return response()->json($metrics);
    }
}
