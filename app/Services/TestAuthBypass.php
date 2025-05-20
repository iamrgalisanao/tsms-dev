<?php

namespace App\Services;

class TestAuthBypass
{
    public function handle($request, $next)
    {
        return $next($request);
    }
}