<?php

// Streamlined Horizon configuration aligned with standardized queue naming:
// transaction-processing (critical), forwarding (medium), low (housekeeping)
return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    // Ensure Horizon uses the same Redis connection as your queues.
    // Priority: HORIZON_CONNECTION > QUEUE_REDIS_CONNECTION > 'horizon' > 'default'.
    'use'    => env('HORIZON_CONNECTION', env('QUEUE_REDIS_CONNECTION', env('QUEUE_HORIZON_FALLBACK','horizon'))),
    'prefix' => env('HORIZON_PREFIX', 'tsms:horizon:'),

    // Optional: CORS headers for Horizon API when served under a subdomain
    // Set HORIZON_CORS_ORIGIN to your dashboard origin (e.g., https://admin.tsms.dev)
    'cors' => [
        'allow_origin' => env('HORIZON_CORS_ORIGIN', null),
    ],

    // Apply your auth middleware / gate (define can:viewHorizon in AuthServiceProvider)
    // Make it configurable to aid staging/debug (e.g., set HORIZON_MIDDLEWARE="web" temporarily)
    'middleware' => array_map('trim', explode(',', env('HORIZON_MIDDLEWARE', 'web,auth,can:viewHorizon'))),

    // Long wait detection thresholds (seconds)
    'waits' => [
        'redis:transaction-processing' => 5,
        'redis:transaction-processing:s0' => 5,
        'redis:transaction-processing:s1' => 5,
        'redis:transaction-processing:s2' => 5,
        'redis:transaction-processing:s3' => 5,
        'redis:transaction-processing:s4' => 5,
        'redis:transaction-processing:s5' => 5,
        'redis:transaction-processing:s6' => 5,
        'redis:transaction-processing:s7' => 5,
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
                'queue'      => [
                    'transaction-processing',
                    'transaction-processing:s0','transaction-processing:s1','transaction-processing:s2','transaction-processing:s3',
                    'transaction-processing:s4','transaction-processing:s5','transaction-processing:s6','transaction-processing:s7'
                ],
                'balance'    => 'auto',
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
                'queue'      => [
                    'transaction-processing',
                    'transaction-processing:s0','transaction-processing:s1','transaction-processing:s2','transaction-processing:s3',
                    'transaction-processing:s4','transaction-processing:s5','transaction-processing:s6','transaction-processing:s7',
                    'forwarding','low','notifications'
                ],
                'balance'    => 'auto',
                'processes'  => 4,
                'tries'      => 2,
            ],
        ],
        'local' => [
            'default' => [
                'connection' => 'redis',
                // Include processing queues locally so Horizon runs workers for them
                'queue'      => [
                    'transaction-processing',
                    'transaction-processing:s0','transaction-processing:s1','transaction-processing:s2','transaction-processing:s3',
                    'transaction-processing:s4','transaction-processing:s5','transaction-processing:s6','transaction-processing:s7',
                    'forwarding','low','notifications','default'
                ],
                'processes'  => 1,
                'tries'      => 1,
            ],
        ],
    ],
];