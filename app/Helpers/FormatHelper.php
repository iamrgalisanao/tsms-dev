<?php

namespace App\Helpers;

use Carbon\Carbon;

class FormatHelper
{
    public static function formatDate($date, $format = 'Y-m-d H:i:s')
    {
        if (!$date) return '';
        try {
            return Carbon::parse($date)->format($format);
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Format a numeric amount as currency (PHP userland display, not for storage).
     * Returns a string with currency symbol and two decimals, e.g. '₱123.45'
     */
    public static function formatCurrency($amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }
}