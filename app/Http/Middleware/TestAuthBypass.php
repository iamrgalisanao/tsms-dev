<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TestAuthBypass
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        return $next($request);
    }
}