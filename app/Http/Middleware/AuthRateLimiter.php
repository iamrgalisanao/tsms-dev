<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\RateLimiter\RateLimiterService;
use Symfony\Component\HttpFoundation\Response;

class AuthRateLimiter
{
    protected $rateLimiter;

    public function __construct(RateLimiterService $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->rateLimiter->attemptRequest($request, 'auth')) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'retry_after_seconds' => 60 * config('rate-limiting.default_limits.auth.decay_minutes')
            ], 429);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            // Count failed login attempts
            $this->rateLimiter->attemptRequest($request, 'auth');
        } else {
            // Clear attempts on successful login
            $this->rateLimiter->clearAttempts($request, 'auth');
        }

        return $response;
    }
}
