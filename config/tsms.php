<?php

return [
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

    /*
    |--------------------------------------------------------------------------
    | Validation Tuning
    |--------------------------------------------------------------------------
    | Adjustable validation parameters. The future timestamp tolerance allows
    | acceptance of transactions that are slightly ahead of server time due to
    | POS clock drift. Set to 0 (default) in production for strict behavior.
    */
    'validation' => [
        'strict_mode' => env('TSMS_VALIDATION_STRICT_MODE', false),
        'net_includes_vat' => env('TSMS_NET_INCLUDES_VAT', true),
        'strict_customer_code_binding' => (bool) env('TSMS_STRICT_CUSTOMER_CODE_BINDING', false),
        'enable_computation_validation' => (bool) env('TSMS_ENABLE_COMPUTATION_VALIDATION', env('APP_ENV') === 'testing'),
        'max_vat_difference' => env('TSMS_MAX_VAT_DIFFERENCE', 0.02),
        'max_rounding_difference' => env('TSMS_MAX_ROUNDING_DIFFERENCE', 0.05),
        'future_timestamp_tolerance_seconds' => (int) env('TSMS_FUTURE_TIMESTAMP_TOLERANCE_SECONDS', 0),
    ],

    'testing' => [
        'capture_only' => (bool) env('TSMS_TESTING_CAPTURE_ONLY', false),
        'allow_capture_only_in_production' => (bool) env('TSMS_ALLOW_CAPTURE_ONLY_IN_PROD', false),
    ],

    'reporting' => [
        'exclude_voids_from_totals' => (bool) env('TSMS_REPORTING_EXCLUDE_VOIDS', true),
    ],

    'transaction_logs' => [
        'max_date_range_days' => (int) env('TSMS_TRANSACTION_LOGS_MAX_RANGE_DAYS', 31),
        'max_per_page' => (int) env('TSMS_TRANSACTION_LOGS_MAX_PER_PAGE', 1000),
        'slow_query_threshold_ms' => (int) env('TSMS_TRANSACTION_LOGS_SLOW_QUERY_MS', 3000),
    ],

    'dlq' => [
        'alert_threshold' => (int) env('TSMS_DLQ_ALERT_THRESHOLD', 10),
    ],

    'rollout' => [
        'pilot_tenants' => array_filter(explode(',', env('TSMS_PILOT_TENANTS', ''))),
    ],

    'intake' => [
        'backpressure' => [
            'enabled' => (bool) env('TSMS_INTAKE_BACKPRESSURE_ENABLED', true),
            'max_queue_depth' => (int) env('TSMS_INTAKE_MAX_QUEUE_DEPTH', 5000),
        ],
        'shard_count' => (int) env('TSMS_INTAKE_SHARD_COUNT', 8),
        'vip_shard' => env('TSMS_INTAKE_VIP_SHARD', 'vip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terminals: Idle Monitor
    |--------------------------------------------------------------------------
    | Controls the background scheduler that detects idle POS terminals based on
    | last_seen_at and per-terminal heartbeat thresholds. Starts in log-only
    | mode. When enabled later, notifications can be turned on behind flags.
    */
    'terminals' => [
        'idle_monitor' => [
            // Master toggle for the scheduler; default off (observation opt-in)
            'enabled' => (bool) env('TSMS_IDLE_MONITOR_ENABLED', false),
            // How often the job runs (minutes); used to build cron expression
            'scan_interval_minutes' => (int) env('TSMS_IDLE_MONITOR_SCAN_MIN', 5),
            // Which activity source determines idleness: last_seen (health) or last_sale (business)
            'activity_basis' => env('TSMS_IDLE_MONITOR_ACTIVITY_BASIS', 'last_seen'), // last_seen|last_sale|composite
            // Default idle window if terminal has no heartbeat_threshold set
            'idle_after_seconds_default' => (int) env('TSMS_IDLE_MONITOR_IDLE_DEFAULT', 3600),
            // Idle threshold multiplier relative to heartbeat_threshold
            'multiplier_of_heartbeat' => (int) env('TSMS_IDLE_MONITOR_MULTIPLIER', 3),
            // Prevent duplicate idle logs/alerts within this TTL
            'dedupe_ttl_seconds' => (int) env('TSMS_IDLE_MONITOR_DEDUPE_TTL', 1800),
            // Optional notifications (kept disabled until validated in logs)
            'notify' => [
                'enabled' => (bool) env('TSMS_IDLE_MONITOR_NOTIFY_ENABLED', false),
                // e.g., ["webapp", "mail", "database"] — not used until enabled
                'channels' => explode(',', env('TSMS_IDLE_MONITOR_NOTIFY_CHANNELS', '')),
            ],
            // Optional per-tenant summaries into AuditLog (lightweight)
            'per_tenant_summary' => [
                'enabled' => (bool) env('TSMS_IDLE_MONITOR_TENANT_SUMMARY', false),
                // When true, only write entries for tenants with any activity (idle or recovered)
                'only_nonzero' => (bool) env('TSMS_IDLE_MONITOR_TENANT_SUMMARY_ONLY_NONZERO', true),
            ],
            // Summary details: optionally include a compact list of changed terminals in each run
            'summary_details' => [
                'include_terminals' => (bool) env('TSMS_IDLE_MONITOR_SUMMARY_TERMINALS', true),
                'terminals_cap' => (int) env('TSMS_IDLE_MONITOR_SUMMARY_TERMINALS_CAP', 25),
                // Optionally include a "currently idle" compact list and count
                'include_currently_idle' => (bool) env('TSMS_IDLE_MONITOR_SUMMARY_CURRENTLY_IDLE', false),
                'currently_idle_cap' => (int) env('TSMS_IDLE_MONITOR_SUMMARY_CURRENTLY_IDLE_CAP', 25),
            ],
        ],
    ],
];
