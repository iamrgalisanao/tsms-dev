<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

class FeatureFlags
{
    /**
     * Return true when computation-based validation is enabled in config/env.
     */
    public static function computationValidationEnabled(): bool
    {
        return (bool) Config::get('tsms.validation.enable_computation_validation', false);
    }
}
