<?php

namespace App\Http\Controllers;

use App\Services\CircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CircuitBreakerController extends Controller
{
    public function __construct(
        private CircuitBreaker $circuitBreaker
    ) {}

    public function testEndpoint(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function testCircuit(Request $request): JsonResponse
    {
        try {
            return $this->circuitBreaker->execute(function () use ($request) {
                if ($request->boolean('should_fail')) {
                    throw new \Exception('Simulated failure');
                }
                return response()->json(['status' => 'success']);
            });
        } catch (\Exception $e) {
            if ($this->circuitBreaker->getState() === 'OPEN') {
                return response()->json(['error' => 'Circuit breaker is open'], 503);
            }
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
