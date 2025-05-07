<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Symfony\Component\HttpFoundation\Response;

class LoginRateLimiter
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'login:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, config('rate-limiting.default_limits.auth.attempts', 5))) {
            $seconds = RateLimiter::availableIn($key);
            
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => $seconds
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        RateLimiter::hit($key, config('rate-limiting.default_limits.auth.decay_minutes', 15) * 60);

        $response = $next($request);

        // If login failed, we should count this attempt
        if ($response->getStatusCode() === Response::HTTP_UNAUTHORIZED) {
            RateLimiter::hit($key, config('rate-limiting.default_limits.auth.decay_minutes', 15) * 60);
        }

        return $response;
    }
}
