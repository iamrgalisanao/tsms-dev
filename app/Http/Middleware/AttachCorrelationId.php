<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttachCorrelationId
{
    public function handle(Request $request, Closure $next)
    {
        $incoming = $request->header('X-Request-Id');
        $cid = !empty($incoming) ? $incoming : (string) Str::uuid();
        // Attach to request for downstream usage
        $request->attributes->set('correlation_id', $cid);
        // Also echo back in response
        $response = $next($request);
        $response->headers->set('X-Request-Id', $cid);
        return $response;
    }
}
