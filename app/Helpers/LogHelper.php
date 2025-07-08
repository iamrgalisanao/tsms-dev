<?php

namespace App\Helpers;

class LogHelper
{
    public static function getLogTypeClass(?string $type): string 
    {
        return match (strtolower($type)) {
            'system' => 'primary',
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            'auth' => 'secondary',
            'transaction' => 'success',
            'webhook' => 'info',
            default => 'secondary'
        };
    }

    public static function getActionTypeClass(?string $type): string 
    {
        return match (strtolower($type)) {
            'create' => 'success',
            'update' => 'info',
            'delete' => 'danger',
            'auth' => 'warning',
            'auth.login' => 'success',
            'auth.logout' => 'secondary',
            'auth.failed' => 'danger',
            default => 'secondary'
        };
    }

    public static function getAuthEventIcon(?string $type): string 
    {
        return match (strtolower($type)) {
            'login' => 'fas fa-sign-in-alt',
            'logout' => 'fas fa-sign-out-alt',
            'login_failed' => 'fas fa-exclamation-triangle',
            default => 'fas fa-question-circle'
        };
    }

    public static function getAuthEventClass(?string $type): string 
    {
        return match (strtolower($type)) {
            'login' => 'success',
            'logout' => 'info',
            'login_failed' => 'danger',
            default => 'secondary'
        };
    }
}