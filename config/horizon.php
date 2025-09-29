<?php

// Streamlined Horizon configuration aligned with standardized queue naming:
// transaction-processing (critical), forwarding (medium), low (housekeeping)
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    'use'    => env('HORIZON_CONNECTION', 'default'),
    'prefix' => env('HORIZON_PREFIX', 'tsms:horizon:'),

    // Apply your auth middleware / gate (define can:viewHorizon in AuthServiceProvider)
    'middleware' => ['web', 'auth', 'can:viewHorizon'],

    // Long wait detection thresholds (seconds)
    'waits' => [
        'redis:transaction-processing' => 5,
        'redis:forwarding'             => 10,
        'redis:low'                    => 15,
        'redis:notifications'          => 5,
    ],

    // Trim windows (minutes)
    'trim' => [
    'recent'        => (int) env('HORIZON_TRIM_RECENT', 60),
    'pending'       => (int) env('HORIZON_TRIM_PENDING', 60),
    'completed'     => (int) env('HORIZON_TRIM_COMPLETED', 120),
    'recent_failed' => (int) env('HORIZON_TRIM_RECENT_FAILED', 43200), // 30 days
    'failed'        => (int) env('HORIZON_TRIM_FAILED', 43200),
    'monitored'     => 43200, // already an int literal
    ],

    'fast_termination' => true,
    'memory_limit'     => 256,

    'environments' => [
        'production' => [
            'high-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['transaction-processing'],
                'balance'    => 'simple',
                'processes'  => env('HZ_HIGH_PROCESSES', 8),
                'tries'      => 3,
                'timeout'    => 30,
                'nice'       => 0,
            ],
            'forward-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['forwarding'],
                'balance'    => 'auto',
                'processes'  => env('HZ_FORWARD_PROCESSES', 4),
                'tries'      => 5,
                'timeout'    => 60,
                'nice'       => 2,
            ],
            'low-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['low'],
                'balance'    => 'auto',
                'processes'  => env('HZ_LOW_PROCESSES', 2),
                'tries'      => 1,
                'timeout'    => 120,
                'nice'       => 5,
            ],
            'notifications-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['notifications'],
                'balance'    => 'simple',
                'processes'  => 2,
                'tries'      => 3,
                'timeout'    => 30,
                'nice'       => 0,
            ],
        ],
        'staging' => [
            'default' => [
                'connection' => 'redis',
                'queue'      => ['transaction-processing','forwarding','low','notifications'],
                'balance'    => 'auto',
                'processes'  => 4,
                'tries'      => 2,
            ],
        ],
        'local' => [
            'default' => [
                'connection' => 'redis',
                // Include processing queues locally so Horizon runs workers for them
                'queue'      => ['transaction-processing','forwarding','low','notifications','default'],
                'processes'  => 1,
                'tries'      => 1,
            ],
        ],
    ],
];