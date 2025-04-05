<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Handle unauthenticated requests.
     */
    protected function unauthenticated($request, array $guards)
    {
        // Return a JSON error response instead of redirecting
        abort(response()->json([
            'status' => 'unauthenticated',
            'message' => 'Authentication required or token invalid.',
        ], 401));
    }
}
