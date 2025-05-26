<?php

namespace App\Helpers;

class BadgeHelper
{
    public static function getValidationStatusBadge($status)
    {
        $class = match(strtoupper($status)) {
            'VALID' => 'success',
            'INVALID' => 'danger',
            'PENDING' => 'warning',
            default => 'secondary'
        };
        return "<span class='badge bg-{$class}'>" . strtoupper($status ?? 'PENDING') . "</span>";
    }

    public static function getJobStatusBadge($status)
    {
        $class = match(strtoupper($status)) {
            'COMPLETED' => 'success',
            'FAILED' => 'danger',
            'PROCESSING' => 'primary',
            'QUEUED' => 'warning',
            default => 'secondary'
        };
        return "<span class='badge bg-{$class}'>" . strtoupper($status) . "</span>";
    }

    public static function getStatusBadgeColor($status)
    {
        return match(strtoupper($status)) {
            'VALID', 'COMPLETED', 'SUCCESS' => 'success',
            'INVALID', 'FAILED', 'ERROR' => 'danger',
            'PENDING', 'WAITING' => 'warning',
            'PROCESSING' => 'info',
            'QUEUED' => 'secondary',
            default => 'secondary'
        };
    }

    public static function getSeverityBadgeColor(string $severity): string
    {
        return match (strtolower($severity)) {
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            'debug' => 'secondary',
            'success' => 'success',
            default => 'secondary'
        };
    }
}