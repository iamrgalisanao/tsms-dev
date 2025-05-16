<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use App\Services\CircuitBreaker;
use Symfony\Component\HttpFoundation\Response;

class CircuitBreakerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $serviceKey = 'default'): Response
    {
        // Create the CircuitBreaker with the service key explicitly
        $circuitBreaker = App::makeWith(CircuitBreaker::class, ['serviceKey' => $serviceKey]);
        
        if (!$circuitBreaker->isAvailable()) {
            return response()->json([
                'error' => 'Service unavailable',
                'service' => $serviceKey,
                'message' => 'Circuit is open due to multiple failures'
            ], 503);
        }
        
        return $next($request);
    }
}