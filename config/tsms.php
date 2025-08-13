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
        'enabled' => false, // Disabled for secured API token integration
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

    /*
    |--------------------------------------------------------------------------
    | Transaction Pruning & Retention
    |--------------------------------------------------------------------------
    | Settings to prune stale or failed transaction records while keeping audit
    | value. PENDING max age protects against stuck jobs. FAILED retention keeps
    | recent diagnostics.
    */
    'transactions' => [
        'prune_failed_after_days' => (int) env('TX_PRUNE_FAILED_AFTER_DAYS', 14),
        'prune_pending_after_minutes' => (int) env('TX_PRUNE_PENDING_AFTER_MIN', 180), // treat as stale
        'enable_pruning' => (bool) env('TX_ENABLE_PRUNING', true),
        'log_channel' => env('TX_PRUNE_LOG_CHANNEL', 'single'),
        // Watchdog settings for stuck / slow transactions
        'watchdog' => [
            'enabled' => (bool) env('TX_WATCHDOG_ENABLED', true),
            // If a transaction stays PENDING this long, mark as FAILED (terminal timeout)
            'max_pending_minutes' => (int) env('TX_WATCHDOG_MAX_PENDING_MIN', 60),
            // Re-dispatch (requeue) PENDING+QUEUED transactions older than this age
            'requeue_after_minutes' => (int) env('TX_WATCHDOG_REQUEUE_AFTER_MIN', 10),
            // Maximum re-dispatch attempts before forcing failure (uses transaction.job_attempts)
            'max_requeue_attempts' => (int) env('TX_WATCHDOG_MAX_REQUEUE_ATTEMPTS', 2),
        ],
    ],
];
