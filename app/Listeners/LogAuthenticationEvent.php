<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use App\Models\AuditLog;
use App\Models\SystemLog;

class LogAuthenticationEvent
{
    public function handle($event): void
    {
        $auditData = [
            'action_type' => 'AUTH',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        $systemData = [
            'type' => 'security',  // Use 'security' instead of 'AUTH'
            'log_type' => 'info',
            'severity' => 'low',
            'terminal_uid' => '00000000-0000-0000-0000-000000000000', // Default UUID for non-terminal auth
            'context' => [
                'auth_type' => 'web',
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]
        ];

        // Handle different auth events
        if ($event instanceof Login) {
            $auditData['user_id'] = $event->user->id;
            $auditData['action'] = 'auth.login';
            $auditData['message'] = 'User logged in successfully';
            
            $systemData['user_id'] = $event->user->id;
            $systemData['message'] = "User {$event->user->name} ({$event->user->email}) logged in successfully";
            $systemData['context']['user_id'] = $event->user->id;
            $systemData['context']['user_email'] = $event->user->email;
            $systemData['context']['auth_event'] = 'login';
            
        } elseif ($event instanceof Logout) {
            $auditData['user_id'] = $event->user->id;
            $auditData['action'] = 'auth.logout';
            $auditData['message'] = 'User logged out';
            
            $systemData['user_id'] = $event->user->id;
            $systemData['message'] = "User {$event->user->name} ({$event->user->email}) logged out";
            $systemData['context']['user_id'] = $event->user->id;
            $systemData['context']['user_email'] = $event->user->email;
            $systemData['context']['auth_event'] = 'logout';
            
        } elseif ($event instanceof Failed) {
            $auditData['action'] = 'auth.failed';
            $auditData['message'] = 'Failed login attempt';
            $auditData['metadata'] = [
                'email' => $event->credentials['email'] ?? 'unknown'
            ];
            
            $systemData['log_type'] = 'warning';
            $systemData['severity'] = 'medium';
            $systemData['message'] = "Failed login attempt for email: " . ($event->credentials['email'] ?? 'unknown');
            $systemData['context']['attempted_email'] = $event->credentials['email'] ?? 'unknown';
            $systemData['context']['auth_event'] = 'login_failed';
        }

        // Log to both audit and system logs
        AuditLog::create($auditData);
        SystemLog::create($systemData);
    }
}
