<?php

// Streamlined Horizon configuration aligned with standardized queue naming:
// transaction-processing (critical), low (housekeeping), notifications
// Note: forwarding queue removed — webapp forwarding is disabled.

// --- Phase 2: Dynamic Intake Sharding ---
$shardCount = max(1, (int) env('TSMS_INTAKE_SHARD_COUNT', 8));
$processingShardCount = max(1, (int) env('TSMS_PROCESSING_SHARD_COUNT', $shardCount));
$vipShardSuffix = env('TSMS_INTAKE_VIP_SHARD', 'vip');

// Generate the list of intake shards s0-s7 (or more) plus the VIP lane.
// We also include the legacy 'transaction-intake' for graceful drainage.
$intakeQueues = array_merge(
    ['transaction-intake'],
    ['transaction-intake:s-' . $vipShardSuffix],
    array_map(fn($i) => "transaction-intake:s$i", range(0, $shardCount - 1))
);

$processingQueues = array_merge(
    ['transaction-processing'],
    array_map(fn($i) => "transaction-processing:s$i", range(0, $processingShardCount - 1))
);

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    // Ensure Horizon's metadata store uses a Redis connection that is always
    // defined in config/database.php. Keep this aligned with the Redis queue
    // store unless a dedicated Horizon Redis store is explicitly configured.
    'use'    => env('HORIZON_CONNECTION', env('QUEUE_REDIS_CONNECTION', 'default')),
    'prefix' => env('HORIZON_PREFIX', 'tsms:horizon:'),

    // Optional: CORS headers for Horizon API when served under a subdomain
    'cors' => [
        'allow_origin' => env('HORIZON_CORS_ORIGIN', null),
    ],

    // Apply your auth middleware / gate (define can:viewHorizon in AuthServiceProvider)
    // Make it configurable to aid staging/debug (e.g., set HORIZON_MIDDLEWARE="web" temporarily)
    'middleware' => array_map('trim', explode(',', env('HORIZON_MIDDLEWARE', 'web,auth,can:viewHorizon'))),

    // Long wait detection thresholds (seconds)
    'waits' => [
        'redis:transaction-intake'          => 1, // Aggressive 1s threshold for intake
        'redis:transaction-processing'      => 5,
        'redis:transaction-processing:s0'   => 5,
        'redis:transaction-processing:s1'   => 5,
        'redis:transaction-processing:s2'   => 5,
        'redis:transaction-processing:s3'   => 5,
        'redis:transaction-processing:s4'   => 5,
        'redis:transaction-processing:s5'   => 5,
        'redis:transaction-processing:s6'   => 5,
        'redis:transaction-processing:s7'   => 5,
        'redis:low'                         => 15,
        'redis:notifications'               => 5,
    ],

    // Trim windows (minutes)
    'trim' => [
        'recent'        => (int) env('HORIZON_TRIM_RECENT', 60),
        'pending'       => (int) env('HORIZON_TRIM_PENDING', 60),
        'completed'     => (int) env('HORIZON_TRIM_COMPLETED', 120),
        'recent_failed' => (int) env('HORIZON_TRIM_RECENT_FAILED', 43200), // 30 days
        'failed'        => (int) env('HORIZON_TRIM_FAILED', 43200),
        'monitored'     => 43200,
    ],

    'fast_termination' => true,
    'memory_limit'     => 512, // Increased for higher throughput

    'environments' => [
        'production' => [
            'intake-supervisor' => [
                'connection' => 'redis',
                'queue'      => $intakeQueues,
                'balance'    => 'auto',
                'processes'  => env('HZ_INTAKE_PROCESSES', 32), // High concurrency for raw intake
                'tries'      => 3,
                'timeout'    => 60,
                'nice'       => 0,
            ],
            'high-supervisor' => [
                'connection' => 'redis',
                'queue'      => [
                    ...$processingQueues,
                ],
                'balance'    => 'auto',
                'processes'  => env('HZ_HIGH_PROCESSES', 16), // Increased for more concurrency
                'tries'      => 5, // More retries for robustness
                'timeout'    => 60, // Increased timeout for longer jobs
                'nice'       => 0,
            ],
            'reporting-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['reporting'],
                'balance'    => 'auto',
                'processes'  => env('HZ_REPORTING_PROCESSES', 2),
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 5,
            ],
            // 'forward-supervisor' disabled — webapp forwarding removed
            'low-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['low'],
                'balance'    => 'auto',
                'processes'  => env('HZ_LOW_PROCESSES', 4), // Increased for background tasks
                'tries'      => 2,
                'timeout'    => 120,
                'nice'       => 5,
            ],
            'notifications-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['notifications'],
                'balance'    => 'auto', // Use auto for dynamic scaling
                'processes'  => 4, // Increased for notification throughput
                'tries'      => 3,
                'timeout'    => 60,
                'nice'       => 0,
            ],
        ],
        'staging' => [
            'intake-supervisor' => [
                'connection' => 'redis',
                'queue'      => $intakeQueues,
                'balance'    => 'auto',
                'processes'  => 12,
                'tries'      => 2,
            ],
            'default' => [
                'connection' => 'redis',
                'queue'      => [
                    ...$processingQueues,
                    'low',
                    'notifications',
                ],
                'balance'    => 'auto',
                'processes'  => 4,
                'tries'      => 2,
            ],
            'reporting-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['reporting'],
                'balance'    => 'auto',
                'processes'  => env('HZ_REPORTING_PROCESSES', 1),
                'tries'      => 3,
                'timeout'    => 300,
                'nice'       => 5,
            ],
        ],
        'local' => [
            'default' => [
                'connection' => 'redis',
                // Include processing queues locally so Horizon runs workers for them
                'queue'      => array_merge($intakeQueues, [
                    ...$processingQueues,
                    'low',
                    'notifications',
                    'default',
                ]),
                'processes'  => 1,
                'tries'      => 1,
            ],
            'reporting-supervisor' => [
                'connection' => 'redis',
                'queue'      => ['reporting'],
                'processes'  => 1,
                'tries'      => 1,
            ],
        ],
    ],
];
