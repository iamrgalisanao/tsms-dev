<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\CircuitBreaker;
use Illuminate\Http\Request;

class CircuitBreakersController extends Controller
{
    public function index(Request $request)
    {
        try {
            \Log::info('Starting circuit breakers fetch');
            \Log::info('Request parameters:', $request->all());
            
            $query = CircuitBreaker::with(['tenant:id,name'])
                ->select([
                    'id',
                    'name',
                    'status',
                    'tenant_id',
                    'trip_count',
                    'last_failure_at',
                    'cooldown_until',
                    'created_at',
                    'updated_at'
                ]);
            
            \Log::info('Query built');

            if ($request->has('tenant') && $request->tenant !== 'all') {
                $query->where('tenant_id', $request->tenant);
            }

            $result = $query->get();

            return response()->json([
                'data' => $result->map(function($item) {
                    return [
                        'id' => $item->id,
                        'name' => $item->name,
                        'status' => $item->status,
                        'tenant_id' => $item->tenant_id,
                        'tenant_name' => $item->tenant->name ?? 'Unknown',
                        'last_failure_at' => $item->last_failure_at ? $item->last_failure_at->toIso8601String() : null,
                        'trip_count' => $item->trip_count ?? 0
                    ];
                })
            ]);
        } catch (\Exception $e) {
            \Log::error('Circuit breaker error: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'error' => 'Failed to fetch circuit breakers',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function reset($id)
    {
        try {
            $circuitBreaker = CircuitBreaker::findOrFail($id);
            $circuitBreaker->status = 'CLOSED';
            $circuitBreaker->trip_count = 0;
            $circuitBreaker->last_failure_at = null;
            $circuitBreaker->cooldown_until = null;
            $circuitBreaker->save();

            return response()->json([
                'message' => 'Circuit breaker reset successfully',
                'data' => $circuitBreaker
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to reset circuit breaker',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
