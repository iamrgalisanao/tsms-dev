<?php

namespace App\Http\Controllers\API\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CircuitBreakerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = \App\Models\CircuitBreaker::with(['tenant:id,name'])
                ->select([
                    'id',
                    'name as service_name',
                    'status as state',
                    'tenant_id',
                    'last_failure_at',
                    'cooldown_until',
                    'created_at',
                    'updated_at'
                ]);

            if ($request->has('tenant_id') && $request->tenant_id !== '') {
                $query->where('tenant_id', $request->tenant_id);
            }

            return response()->json($query->get());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch circuit breakers',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function reset($id)
    {
        try {
            $circuitBreaker = \App\Models\CircuitBreaker::findOrFail($id);
            $circuitBreaker->status = 'CLOSED';
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
