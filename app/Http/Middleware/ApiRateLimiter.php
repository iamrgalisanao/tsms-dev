<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\RateLimiter\RateLimiterService;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimiter
{
    protected $rateLimiter;

    public function __construct(RateLimiterService $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
    }

    public function handle(Request $request, Closure $next, string $type = 'api'): Response
    {
        if (!$this->rateLimiter->attemptRequest($request, $type)) {
            return response()->json([
                'message' => 'Too Many Attempts.',
                'retry_after' => $this->rateLimiter->getRemainingAttempts($request, $type)
            ], 429);
        }

        $response = $next($request);

        // Add rate limit headers to response
        return $response->withHeaders([
            'X-RateLimit-Remaining' => $this->rateLimiter->getRemainingAttempts($request, $type),
            'X-RateLimit-Type' => $type
        ]);
    }
}
