<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Security\Contracts\SecurityMonitorInterface;
use Symfony\Component\HttpFoundation\Response;

class SecurityMonitorMiddleware
{
    protected $securityMonitor;

    public function __construct(SecurityMonitorInterface $securityMonitor)
    {
        $this->securityMonitor = $securityMonitor;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Record the request for security monitoring
        $this->securityMonitor->recordEvent(
            'api_request',
            'info',
            [
                'path' => $request->path(),
                'method' => $request->method(),
                'tenant_id' => $request->header('X-Tenant-ID'),
            ],
            $request->ip(),
            $request->user()?->id
        );

        $response = $next($request);

        // Record response status for monitoring
        if ($response->getStatusCode() >= 400) {
            $this->securityMonitor->recordEvent(
                'api_error',
                'warning',
                [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'status' => $response->getStatusCode(),
                    'tenant_id' => $request->header('X-Tenant-ID'),
                ],
                $request->ip(),
                $request->user()?->id
            );
        }

        return $response;
    }
}
