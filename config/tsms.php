<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WebApp Integration Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for forwarding validated transactions to the web application
    |
    */
    'web_app' => [
        'endpoint' => env('WEBAPP_FORWARDING_ENDPOINT'),
        'timeout' => (int) env('WEBAPP_FORWARDING_TIMEOUT', 30),
        'batch_size' => (int) env('WEBAPP_FORWARDING_BATCH_SIZE', 50),
        'auth_token' => env('WEBAPP_FORWARDING_AUTH_TOKEN'),
        'verify_ssl' => (bool) env('WEBAPP_FORWARDING_VERIFY_SSL', true),
        'enabled' => (bool) env('WEBAPP_FORWARDING_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the circuit breaker pattern to prevent cascading failures
    |
    */
    'circuit_breaker' => [
        'failure_threshold' => (int) env('WEBAPP_CB_FAILURE_THRESHOLD', 5),
        'recovery_timeout_minutes' => (int) env('WEBAPP_CB_RECOVERY_TIMEOUT', 10),
        'enabled' => (bool) env('WEBAPP_CB_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for retry logic when forwarding fails
    |
    */
    'retry' => [
        'max_attempts' => (int) env('WEBAPP_RETRY_MAX_ATTEMPTS', 3),
        'base_delay_minutes' => (int) env('WEBAPP_RETRY_BASE_DELAY', 5),
        'max_delay_minutes' => (int) env('WEBAPP_RETRY_MAX_DELAY', 120),
        'exponential_base' => (int) env('WEBAPP_RETRY_EXPONENTIAL_BASE', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Logging
    |--------------------------------------------------------------------------
    |
    | Settings for monitoring and logging webapp forwarding activity
    |
    */
    'monitoring' => [
        'log_channel' => env('WEBAPP_LOG_CHANNEL', 'single'),
        'log_successful_forwards' => (bool) env('WEBAPP_LOG_SUCCESS', true),
        'log_failed_forwards' => (bool) env('WEBAPP_LOG_FAILURES', true),
        'alert_on_circuit_breaker' => (bool) env('WEBAPP_ALERT_ON_CB', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Settings to control performance and resource usage
    |
    */
    'performance' => [
        'cleanup_completed_after_days' => (int) env('WEBAPP_CLEANUP_COMPLETED_DAYS', 30),
        'cleanup_failed_after_days' => (int) env('WEBAPP_CLEANUP_FAILED_DAYS', 7),
        'enable_auto_cleanup' => (bool) env('WEBAPP_AUTO_CLEANUP', true),
    ],
];
