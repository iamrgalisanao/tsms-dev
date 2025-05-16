<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class CircuitBreakersController extends Controller
{
    public function getStates(Request $request)
    {
        try {
            Log::info('Fetching circuit breaker states');
            
            $query = CircuitBreaker::select([
                'id',
                'name',
                'status',
                'tenant_id',
                'trip_count',
                'cooldown_until'
            ]);

            if ($request->has('tenant_id')) {
                $query->where('tenant_id', $request->tenant_id);
            }
            
            $states = $query->get();
            Log::info('Retrieved states:', ['count' => $states->count()]);

            return response()->json($states);
        } catch (\Exception $e) {
            Log::error('Error fetching circuit breaker states: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch circuit breaker states'], 500);
        }
    }

    public function getMetrics(Request $request)
    {
        try {
            $service = $request->query('service');
            $tenantId = $request->query('tenant');
            
            Log::info('Fetching metrics', [
                'service' => $service,
                'tenant' => $tenantId
            ]);

            if (!$service) {
                return response()->json(['error' => 'Service name is required'], 400);
            }

            // Generate some test metrics if Redis is empty
            $key = "circuit_breaker:{$tenantId}:{$service}:metrics";
            $metrics = Redis::get($key);

            if (!$metrics) {
                Log::info('No metrics found in Redis, generating test data');
                // Generate test data for development
                $timestamps = [];
                $failureRates = [];
                $responseTimes = [];
                
                for ($i = 10; $i >= 0; $i--) {
                    $timestamps[] = now()->subMinutes($i)->format('H:i:s');
                    $failureRates[] = rand(0, 100) / 10; // 0-10% failure rate
                    $responseTimes[] = rand(50, 500); // 50-500ms response time
                }

                $metrics = [
                    'timestamps' => $timestamps,
                    'failure_rates' => $failureRates,
                    'response_times' => $responseTimes
                ];

                // Store in Redis for 1 minute
                Redis::setex($key, 60, json_encode($metrics));
                
                Log::info('Generated test metrics', ['metrics' => $metrics]);
                return response()->json($metrics);
            }

            $metrics = json_decode($metrics, true);
            Log::info('Retrieved metrics from Redis', [
                'dataPoints' => count($metrics['timestamps'] ?? [])
            ]);

            return response()->json($metrics);
        } catch (\Exception $e) {
            Log::error('Error fetching metrics: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch metrics'], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            Log::info('Fetching circuit breakers');
            
            $query = CircuitBreaker::with(['tenant:id,name'])
                ->select([
                    'id',
                    'name as service_name',
                    'status as state',
                    'tenant_id',
                    'last_failure_at',
                    'cooldown_until',
                    'trip_count',  // Added trip_count
                    'created_at',
                    'updated_at'
                ]);

            if ($request->has('tenant_id') && $request->tenant_id !== '') {
                $query->where('tenant_id', $request->tenant_id);
            }

            $result = $query->get();
            Log::info('Circuit breakers fetched', ['count' => $result->count()]);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Failed to fetch circuit breakers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch circuit breakers',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}