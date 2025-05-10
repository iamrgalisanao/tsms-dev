<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Security Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for the security monitoring system.
    | It includes settings for login attempts, alert thresholds, and notification
    | channels.
    |
    */

    // Login Security Settings
    'max_login_attempts' => env('SECURITY_MAX_LOGIN_ATTEMPTS', 5),
    'login_decay_minutes' => env('SECURITY_LOGIN_DECAY_MINUTES', 15),
    
    // Alert Settings
    'alert_channels' => [
        'email' => [
            'enabled' => env('SECURITY_ALERT_EMAIL_ENABLED', true),
            'recipients' => explode(',', env('SECURITY_ALERT_EMAIL_RECIPIENTS', '')),
        ],
        'slack' => [
            'enabled' => env('SECURITY_ALERT_SLACK_ENABLED', false),
            'webhook_url' => env('SECURITY_ALERT_SLACK_WEBHOOK', ''),
        ],
    ],

    // Event Settings
    'events' => [
        'retention_days' => env('SECURITY_EVENT_RETENTION_DAYS', 90),
        'high_risk_types' => [
            'failed_login',
            'password_reset',
            'permission_change',
            'config_change',
        ],
    ],

    // Monitoring Settings
    'monitoring' => [
        'enabled' => env('SECURITY_MONITORING_ENABLED', true),
        'log_channel' => env('SECURITY_LOG_CHANNEL', 'security'),
        'redis_prefix' => env('SECURITY_REDIS_PREFIX', 'security:'),
    ],

    // Integration Settings
    'integrations' => [
        'circuit_breaker' => [
            'security_threshold' => env('SECURITY_CIRCUIT_BREAKER_THRESHOLD', 10),
            'cooldown_minutes' => env('SECURITY_CIRCUIT_BREAKER_COOLDOWN', 30),
        ],
        'rate_limiting' => [
            'security_max_attempts' => env('SECURITY_RATE_LIMIT_MAX', 100),
            'security_decay_minutes' => env('SECURITY_RATE_LIMIT_DECAY', 1),
        ],
    ],
];
