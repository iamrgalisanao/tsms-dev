<?php

return [
    'validation' => [
        'strict_mode' => env('TSMS_VALIDATION_STRICT_MODE', false),
        'net_includes_vat' => env('TSMS_NET_INCLUDES_VAT', true),
        'max_vat_difference' => env('TSMS_MAX_VAT_DIFFERENCE', 0.02),
        'max_rounding_difference' => env('TSMS_MAX_ROUNDING_DIFFERENCE', 0.05),
        'future_timestamp_tolerance_seconds' => (int) env('TSMS_FUTURE_TIMESTAMP_TOLERANCE_SECONDS', 0),
    ],

    'testing' => [
        'capture_only' => (bool) env('TSMS_TESTING_CAPTURE_ONLY', false),
        'allow_capture_only_in_production' => (bool) env('TSMS_ALLOW_CAPTURE_ONLY_IN_PROD', false),
    ],

    'web_app' => [
        'endpoint' => env('WEBAPP_FORWARDING_ENDPOINT'),
        'timeout' => (int) env('WEBAPP_FORWARDING_TIMEOUT', 30),
        'batch_size' => (int) env('WEBAPP_FORWARDING_BATCH_SIZE', 50),
        'auth_token' => env('WEBAPP_FORWARDING_AUTH_TOKEN'),
        'verify_ssl' => (bool) env('WEBAPP_FORWARDING_VERIFY_SSL', true),
        'enabled' => (bool) env('WEBAPP_FORWARDING_ENABLED', false),
    ],

    'transactions' => [
        'prune_failed_after_days' => (int) env('TX_PRUNE_FAILED_AFTER_DAYS', 14),
        'prune_pending_after_minutes' => (int) env('TX_PRUNE_PENDING_AFTER_MIN', 180),
        'enable_pruning' => (bool) env('TX_ENABLE_PRUNING', true),
    ],
];
