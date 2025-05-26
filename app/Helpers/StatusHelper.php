<?php

namespace App\Helpers;

class StatusHelper
{
    public static function getValidationStatusClass($status)
    {
        return match(strtoupper($status)) {
            'VALID' => 'success',
            'INVALID' => 'danger',
            'PENDING' => 'warning',
            default => 'secondary'
        };
    }

    public static function getJobStatusClass($status)
    {
        return match(strtoupper($status)) {
            'COMPLETED' => 'success',
            'FAILED' => 'danger',
            'PROCESSING' => 'primary',
            'QUEUED' => 'warning',
            default => 'secondary'
        };
    }
}
