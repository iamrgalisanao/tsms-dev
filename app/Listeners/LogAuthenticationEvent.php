<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use App\Models\AuditLog;

class LogAuthenticationEvent
{
    public function handle($event): void
    {
        $data = [
            'action_type' => 'AUTH',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        // Handle different auth events
        if ($event instanceof Login) {
            $data['user_id'] = $event->user->id;
            $data['action'] = 'auth.login';
            $data['message'] = 'User logged in successfully';
        } elseif ($event instanceof Logout) {
            $data['user_id'] = $event->user->id;
            $data['action'] = 'auth.logout';
            $data['message'] = 'User logged out';
        } elseif ($event instanceof Failed) {
            $data['action'] = 'auth.failed';
            $data['message'] = 'Failed login attempt';
            $data['metadata'] = [
                'email' => $event->credentials['email'] ?? 'unknown'
            ];
        }

        AuditLog::create($data);
    }
}
