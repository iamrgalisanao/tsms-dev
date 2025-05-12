<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JWTDebugMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Log the auth header for debugging
        Log::debug('JWT Debug Middleware: '.json_encode([
            'has_auth_header' => $request->hasHeader('Authorization'),
            'auth_header' => $request->header('Authorization'),
            'token_exists' => auth('pos_api')->check(),
            'token_user' => auth('pos_api')->check() ? auth('pos_api')->user()->id : null,
        ]));
        
        return $next($request);
    }
}