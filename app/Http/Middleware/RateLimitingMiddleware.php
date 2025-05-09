<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Cache\RateLimiting\Limit;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RateLimitingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $limiterKey
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $limiterKey = 'api')
    {
        $tenant = $request->header('X-Tenant-ID', 'default');
        $key = $this->generateRateLimitKey($request, $limiterKey, $tenant);
        $limits = $this->getLimits($limiterKey);

        $response = $this->checkRateLimit($key, $limits, $next, $request);

        return $this->addRateLimitHeaders($response, $key, $limits);
    }

    /**
     * Generate a unique rate limit key based on the request
     */
    protected function generateRateLimitKey(Request $request, string $limiterKey, string $tenant): string
    {
        $identifier = $request->user() 
            ? $request->user()->id 
            : $request->ip();

        return sprintf(
            'rate_limit:%s:%s:%s',
            $limiterKey,
            $tenant,
            $identifier
        );
    }

    /**
     * Get the rate limit configuration
     */
    protected function getLimits(string $limiterKey): array
    {
        return Config::get("rate-limiting.default_limits.{$limiterKey}", [
            'attempts' => 60,
            'decay_minutes' => 1,
        ]);
    }

    /**
     * Check if the request exceeds the rate limit
     */
    protected function checkRateLimit(string $key, array $limits, Closure $next, Request $request)
    {
        $executed = RateLimiter::attempt(
            $key,
            $limits['attempts'],
            function() use ($next, $request) {
                return $next($request);
            },
            $limits['decay_minutes'] * 60
        );

        if (!$executed) {
            return $this->buildRateLimitExceededResponse();
        }

        return $executed;
    }

    /**
     * Add rate limit headers to the response
     */    protected function addRateLimitHeaders($response, string $key, array $limits)
    {
        $remaining = RateLimiter::remaining($key, $limits['attempts']);
        $decayMinutes = (int) $limits['decay_minutes'];
        $headers = [
            'X-RateLimit-Limit' => $limits['attempts'],
            'X-RateLimit-Remaining' => max(0, $remaining),
            'X-RateLimit-Reset' => now()->addMinutes($decayMinutes)->getTimestamp(),
        ];

        if ($response instanceof SymfonyResponse) {
            foreach ($headers as $header => $value) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildRateLimitExceededResponse(): Response
    {
        return new Response(response()->json([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
        ], 429)->getContent(), 429, ['Content-Type' => 'application/json']);
    }
}