<?php

namespace App\Helpers;

class LogHelper
{
    public static function getLogTypeClass(string $type): string
    {
        return match (strtolower($type)) {
            'error' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            'info' => 'info',
            'system' => 'primary',
            'audit' => 'secondary',
            'webhook' => 'info',
            'transaction' => 'primary',
            'debug' => 'secondary',
            default => 'secondary'
        };
    }
}