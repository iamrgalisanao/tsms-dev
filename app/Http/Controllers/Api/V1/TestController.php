<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
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
}
