<?php

return [
    // Ingestion modes:
    // STRICT (default)     - current behaviour: reject on checksum mismatch (422)
    // QUARANTINE           - store raw payload for triage but still return 422 (safe)
    // ACCEPT_WITH_ISSUES   - persist transactions and mark them WITH_ISSUES (feature-flagged)
    'default_mode' => env('TSMS_INGESTION_MODE', 'ACCEPT_WITH_ISSUES'),

    // Quarantine related settings
    'quarantine' => [
        // Allow replay --execute to actually re-post quarantined payloads.
        // Default false in order to require explicit opt-in in environments
        // where running in-process ingestion is dangerous.
        'allow_replay_execute' => env('TSMS_INGESTION_QUARANTINE_ALLOW_REPLAY', false),

        // Allow showing full payload via `ingestion:quarantine:show --force`
        'allow_show_full_payload' => env('TSMS_INGESTION_QUARANTINE_ALLOW_SHOW_FULL', false),

        // Retention days for quarantine rows (purge scheduler can use this)
        'retention_days' => (int) env('TSMS_INGESTION_QUARANTINE_RETENTION_DAYS', 30),
    ],
];
