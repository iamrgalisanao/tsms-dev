<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transaction Failure Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring transaction failures and sending alerts
    | when thresholds are exceeded.
    |
    */

    'transaction_failure_threshold' => env('NOTIFICATION_TRANSACTION_FAILURE_THRESHOLD', 10),
    'transaction_failure_time_window' => env('NOTIFICATION_TRANSACTION_FAILURE_TIME_WINDOW', 60), // minutes

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring batch processing failures.
    |
    */

    'batch_failure_threshold' => env('NOTIFICATION_BATCH_FAILURE_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Security Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for security-related notifications.
    |
    */

    'security_alerts_enabled' => env('NOTIFICATION_SECURITY_ALERTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Notification Channels
    |--------------------------------------------------------------------------
    |
    | Default channels for sending notifications. Available: mail, database, slack
    |
    */

    'notification_channels' => [
        'mail',
        'database',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Notification Recipients
    |--------------------------------------------------------------------------
    |
    | Email addresses that should receive admin notifications.
    | 
    /**
     * The environment variable configuration for admin notification emails.
     * 
     * This setting expects a comma-separated list of email addresses that will receive 
     * administrative notifications. These email addresses should be configured in the 
     * NOTIFICATION_ADMIN_EMAILS environment variable.
     * 
     * Example format: "admin1@example.com,admin2@example.com,admin3@example.com"
     * 
     * Example implementation:
     * In your .env file:
     * NOTIFICATION_ADMIN_EMAILS=admin@tsms.com,alerts@tsms.com,support@tsms.com
     * 
     * In your code:
     * $adminEmails = config('notifications.admin_emails');
     * Notification::route('mail', $adminEmails)->notify(new AdminAlert($message));
     * 
     * @var string NOTIFICATION_ADMIN_EMAILS
     

    | The NOTIFICATION_ADMIN_EMAILS environment variable should be a comma-separated list of email addresses.
    |
    */

    'admin_emails' => array_filter(explode(',', env('NOTIFICATION_ADMIN_EMAILS', 'admin@tsms.com'))),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for queued notifications.
    |
    */

    'queue_notifications' => env('NOTIFICATION_QUEUE_ENABLED', true),
    'notification_queue' => env('NOTIFICATION_QUEUE_NAME', 'notifications'),

    /*
    |--------------------------------------------------------------------------
    | Notification Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent spam by limiting notification frequency.
    |
    */

    'rate_limiting' => [
        'enabled' => env('NOTIFICATION_RATE_LIMITING_ENABLED', true),
        'max_per_hour' => env('NOTIFICATION_MAX_PER_HOUR', 10),
        'cooldown_minutes' => env('NOTIFICATION_COOLDOWN_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep notifications in the database.
    |
    */

    'retention_days' => env('NOTIFICATION_RETENTION_DAYS', 90),

];