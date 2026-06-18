<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    | Configurations for different rate limiting scenarios in the application
    */

    'storage' => [
        'driver' => 'redis',
        'connection' => 'rate-limits', // Dedicated Redis connection for rate limiting
    ],

    'default_limits' => [
        'api' => [
            'attempts' => env('RATE_LIMIT_API_ATTEMPTS', 60),
            'decay_minutes' => env('RATE_LIMIT_API_DECAY_MINUTES', 1),
        ],
        'pos_ingestion' => [
            'attempts' => env('RATE_LIMIT_POS_INGESTION_ATTEMPTS', 120),
            'decay_minutes' => env('RATE_LIMIT_POS_INGESTION_DECAY_MINUTES', 1),
        ],
        'pos_read' => [
            'attempts' => env('RATE_LIMIT_POS_READ_ATTEMPTS', 300),
            'decay_minutes' => env('RATE_LIMIT_POS_READ_DECAY_MINUTES', 1),
        ],
        'pos_auth' => [
            'attempts' => env('RATE_LIMIT_POS_AUTH_ATTEMPTS', 10),
            'decay_minutes' => env('RATE_LIMIT_POS_AUTH_DECAY_MINUTES', 1),
        ],
        'pos_heartbeat' => [
            'attempts' => env('RATE_LIMIT_POS_HEARTBEAT_ATTEMPTS', 120),
            'decay_minutes' => env('RATE_LIMIT_POS_HEARTBEAT_DECAY_MINUTES', 1),
        ],
        'auth' => [
            'attempts' => env('RATE_LIMIT_AUTH_ATTEMPTS', 5),
            'decay_minutes' => env('RATE_LIMIT_AUTH_DECAY_MINUTES', 15),
        ],
        'webapp' => [
            'attempts' => env('RATE_LIMIT_WEBAPP_ATTEMPTS', 300),
            'decay_minutes' => env('RATE_LIMIT_WEBAPP_DECAY_MINUTES', 1),
        ],
        'circuit_breaker' => [
            'attempts' => env('RATE_LIMIT_CB_ATTEMPTS', 30),
            'decay_minutes' => env('RATE_LIMIT_CB_DECAY_MINUTES', 1),
        ],
    ],

    'tenant_specific' => [
        'enabled' => true,
        'key_prefix' => 'rate_limit:tenant:',
    ],

    // For feature tests: allow enabling rate limiting behavior inside the testing environment
    'enable_in_tests' => env('RATE_LIMIT_ENABLE_IN_TESTS', false),
];
