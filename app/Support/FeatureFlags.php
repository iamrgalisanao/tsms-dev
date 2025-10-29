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
        // Default behavior:
        // - If the config key is explicitly set, honor it.
        // - Otherwise, enable computation-based validation in the testing
        //   environment so unit tests that expect reconciliation logic to run
        //   behave correctly. Production/other envs will remain passive by default.
        if (Config::has('tsms.validation.enable_computation_validation')) {
            return (bool) Config::get('tsms.validation.enable_computation_validation');
        }

        return app()->environment('testing');
    }
}
