<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('v1/*')) {
            return null;
        }

        // Don't store intended URL for API routes
        if (!$request->is('api/*') && !$request->is('v1/*')) {
            $request->session()->put('url.intended', $request->url());
        }

        return route('login');
    }
}
