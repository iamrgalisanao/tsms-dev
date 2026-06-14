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

    public static function formatCurrency($amount)
    {
        if ($amount === null || $amount === '') return '-';
        return '₱' . number_format((float) $amount, 2);
    }
}