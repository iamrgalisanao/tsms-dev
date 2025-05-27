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
}