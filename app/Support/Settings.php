<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class Settings
{
    /**
     * Get setting value with optional default.
     * Caches settings for 60 seconds to avoid DB hit on each validation.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = 'system_setting_' . $key;
        return Cache::remember($cacheKey, 60, function () use ($key, $default) {
            $row = SystemSetting::where('key', $key)->first();
            if (! $row) {
                return $default;
            }
            switch ($row->type) {
                case 'boolean':
                    return in_array($row->value, ['1', 'true', 'on', 'yes'], true);
                case 'int':
                case 'integer':
                    return (int) $row->value;
                case 'json':
                    return json_decode($row->value, true);
                default:
                    return $row->value;
            }
        });
    }

    /**
     * Set a setting value and invalidate cache.
     *
     * @param string $key
     * @param mixed $value
     * @param string $type
     * @return SystemSetting
     */
    public static function set(string $key, $value, string $type = 'string')
    {
        $row = SystemSetting::updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'type' => $type]
        );
        Cache::forget('system_setting_' . $key);
        return $row;
    }
}
