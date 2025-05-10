<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Security\Contracts\LoginSecurityMonitorInterface;
use Symfony\Component\HttpFoundation\Response;

class LoginSecurityMiddleware
{
    protected $loginMonitor;

    public function __construct(LoginSecurityMonitorInterface $loginMonitor)
    {
        $this->loginMonitor = $loginMonitor;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only monitor login attempts
        if ($request->is('api/*/login') || $request->is('login')) {
            $email = $request->input('email');
            $sourceIp = $request->ip();

            // Check if the user is already blocked
            if ($this->loginMonitor->isBlocked($email, $sourceIp)) {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'error' => 'login_blocked'
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }

            // Record the login attempt result
            if ($response->getStatusCode() === Response::HTTP_OK) {
                $this->loginMonitor->recordSuccessfulLogin(
                    $request->user()->id,
                    $sourceIp,
                    ['email' => $email]
                );
            } else {
                $this->loginMonitor->recordFailedLogin(
                    $email,
                    $sourceIp,
                    [
                        'user_agent' => $request->userAgent(),
                        'status_code' => $response->getStatusCode()
                    ]
                );
            }
        }

        return $response;
    }
}
