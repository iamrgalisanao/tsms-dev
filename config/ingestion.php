<?php

return [
    'quarantine' => [
        'enabled' => (bool) env('TSMS_INGESTION_QUARANTINE_ENABLED', true),
        'allow_show_full_payload' => (bool) env('TSMS_QUARANTINE_ALLOW_SHOW_FULL_PAYLOAD', false),
        'allow_replay_execute' => (bool) env('TSMS_QUARANTINE_ALLOW_REPLAY_EXECUTE', false),
    ],

    // Transaction timestamp sanity checks at intake (003-ingestion-hardening US5).
    // mode: 'observe' (log + metric only, accept as today) | 'enforce' (422).
    'timestamps' => [
        'mode' => env('INGESTION_TS_MODE', 'observe'),
        'earliest' => env('INGESTION_TS_EARLIEST', '2020-01-01'),
        'future_tolerance_minutes' => (int) env('INGESTION_TS_FUTURE_TOLERANCE_MINUTES', 1440),
        'late_threshold_minutes' => (int) env('INGESTION_TS_LATE_THRESHOLD_MINUTES', 1440),
    ],

    // Intake rejection monitoring and alerting (003-ingestion-hardening US3).
    'rejection_alerts' => [
        'enabled' => (bool) env('INGESTION_REJECTION_ALERTS_ENABLED', true),
        'window_minutes' => (int) env('INGESTION_REJECTION_ALERT_WINDOW_MINUTES', 60),
        'threshold' => (int) env('INGESTION_REJECTION_ALERT_THRESHOLD', 100),
        'no_accept_hours' => (int) env('INGESTION_REJECTION_ALERT_NO_ACCEPT_HOURS', 24),
        'cooldown_minutes' => (int) env('INGESTION_REJECTION_ALERT_COOLDOWN_MINUTES', 360),
    ],
];
