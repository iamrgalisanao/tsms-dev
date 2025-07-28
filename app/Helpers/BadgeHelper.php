<?php

namespace App\Helpers;

class BadgeHelper
{
    public static function getValidationStatusBadge($status)
    {
        $statusStr = $status ?? 'PENDING';
        $class = match(strtoupper($statusStr)) {
            'VALID' => 'success',
            'INVALID' => 'danger',
            'PENDING' => 'warning',
            default => 'secondary'
        };
        return "<span class='badge bg-{$class}'>" . strtoupper($statusStr) . "</span>";
    }

    public static function getJobStatusBadge($status)
    {
        $statusStr = $status ?? 'QUEUED';
        $class = match(strtoupper($statusStr)) {
            'COMPLETED' => 'success',
            'FAILED' => 'danger',
            'PROCESSING' => 'primary',
            'QUEUED' => 'warning',
            default => 'secondary'
        };
        return "<span class='badge bg-{$class}'>" . strtoupper($statusStr) . "</span>";
    }

    public static function getStatusBadgeColor($status)
    {
        $statusStr = $status ?? 'PENDING';
        return match(strtoupper($statusStr)) {
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