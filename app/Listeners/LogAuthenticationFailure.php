<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Request;

class LogAuthenticationFailure
{
    public function handle(Failed $event): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => 'auth.login_failed',
            'action_type' => 'AUTH',
            'resource_type' => 'User',
            'resource_id' => $event->credentials['email'] ?? 'unknown',
            'ip_address' => Request::ip(),
            'message' => 'Failed login attempt',
            'metadata' => [
                'email' => $event->credentials['email'] ?? 'unknown',
                'user_agent' => Request::userAgent(),
                'attempt_time' => now()->toIso8601String()
            ]
        ]);
    }
}