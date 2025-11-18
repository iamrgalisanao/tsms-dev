<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnsureWebappToken
{
    /**
     * Ensure the incoming request is authenticated with a token that has webapp read abilities.
     */
    public function handle(Request $request, Closure $next)
    {
        // Feature flag: allow disabling the webapp API globally
        if (! Config::get('webapp_api.enabled', false)) {
            return response()->json(['message' => 'Webapp API is disabled'], 503);
        }

        // IP allowlist enforcement (when configured)
        $allowed = Config::get('webapp_api.allowed_ips', []);
        if (! empty($allowed) && is_array($allowed)) {
            $clientIp = $request->ip();
            // Also respect X-Forwarded-For first entry when present
            $xff = $request->header('X-Forwarded-For');
            if ($xff) {
                $parts = array_map('trim', explode(',', $xff));
                if (! empty($parts[0])) {
                    $clientIp = $parts[0];
                }
            }

            if (! in_array($clientIp, $allowed, true)) {
                Log::warning('Webapp API request blocked by IP allowlist', ['ip' => $clientIp, 'allowed' => $allowed, 'path' => $request->path()]);
                return response()->json(['message' => 'Forbidden - IP not allowed'], 403);
            }
        }

        $user = $request->user();
        // 1) Allow static bearer tokens configured in config (simple mode)
        $bearer = $request->bearerToken();
        if ($bearer && Config::get('webapp_api.use_static_tokens', false)) {
            $static = Config::get('webapp_api.static_tokens', []);
            if (is_array($static) && in_array($bearer, $static, true)) {
                // Mark the request with a short identifier for downstream caching.
                $request->attributes->set('webapp.static_token_id', Str::limit(md5($bearer), 32));
                return $next($request);
            }
        }

        // 2) Otherwise, require an authenticated Sanctum user and token ability
        // If there's no authenticated user, return 401 so auth middleware can be explicit
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Prefer tokenCan (Sanctum) check; also allow token model's can() when available.
        $allowed = false;

        try {
            if (method_exists($user, 'tokenCan') && $user->tokenCan(Config::get('webapp_api.token_ability', 'webapp:read'))) {
                $allowed = true;
            }

            $current = $user->currentAccessToken();
            if (! $allowed && $current && method_exists($current, 'can') && $current->can(Config::get('webapp_api.token_ability', 'webapp:read'))) {
                $allowed = true;
            }
        } catch (\Throwable $e) {
            // Fall through to denied
            $allowed = false;
        }

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden - token lacks required ability'], 403);
        }

        return $next($request);
    }
}
