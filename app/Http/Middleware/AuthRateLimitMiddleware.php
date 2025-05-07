<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthRateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $executed = RateLimiter::attempt(
            'auth',
            config('rate-limiting.default_limits.auth.attempts', 5),
            function() use ($next, $request) {
                return $next($request);
            },
            config('rate-limiting.default_limits.auth.decay_minutes', 15) * 60
        );

        if (! $executed) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => RateLimiter::availableIn('auth:' . $request->ip())
            ], 429);
        }

        return $next($request);
    }
}
