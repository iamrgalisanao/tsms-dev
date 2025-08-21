<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip rate limiting during testing
        if (app()->environment('testing') || config('app.env') === 'testing') {
            return $next($request);
        }
        
        $key = 'rate_limit:' . $request->ip();
        $maxAttempts = 5; // 5 requests per minute
        $decayMinutes = 1;

        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.'
            ], 429);
        }

    Cache::put($key, $attempts + 1, (int)($decayMinutes * 60));

        return $next($request);
    }
}
