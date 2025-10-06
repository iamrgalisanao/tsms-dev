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
        // Default to false to preserve passive ingestion: do not recompute or
        // mutate incoming amounts during validation. Environments may opt-in
        // to computation-based validation via config if desired.
        return (bool) Config::get('tsms.validation.enable_computation_validation', false);
    }
}
