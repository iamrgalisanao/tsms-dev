<?php

return [
    'quarantine' => [
        'enabled' => (bool) env('TSMS_INGESTION_QUARANTINE_ENABLED', true),
        'allow_show_full_payload' => (bool) env('TSMS_QUARANTINE_ALLOW_SHOW_FULL_PAYLOAD', false),
        'allow_replay_execute' => (bool) env('TSMS_QUARANTINE_ALLOW_REPLAY_EXECUTE', false),
    ],
];
