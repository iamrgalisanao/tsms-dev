<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This should match one of your Redis cluster connections defined in
    | config/database.php (usually 'default').
    */
    'use' => env('HORIZON_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        // 'web', 'auth',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Times
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:default'           => 60,
        'redis:transactions'      => 30,
        'redis:notifications'     => 90,
        'redis:priority'          => 15,
        'redis:webapp-forwarding' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent'    => 60,
        'pending'   => 60,
        'completed' => 60,
        'failed'    => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */
    'silenced' => [
        // Frequent but low‑priority jobs
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */
    'fast_termination' => env('HORIZON_FAST_TERMINATION', false),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */
    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Environment Configurations
    |--------------------------------------------------------------------------
    */
    'environments' => [
        'production' => [
            'transaction-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['priority', 'transactions', 'webapp-forwarding'],
                'balance'      => 'auto',
                'maxProcesses' => 25,
                'maxJobs'      => 100,
                'memory'       => 512,
                'tries'        => 3,
                'timeout'      => 300,
                'sleep'        => 3,
                'rest'         => 30,
            ],
            'notification-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['notifications', 'webhooks', 'emails', 'webapp-forwarding'],
                'balance'      => 'auto',
                'maxProcesses' => 15,
                'maxJobs'      => 200,
                'memory'       => 256,
                'tries'        => 5,
                'timeout'      => 120,
                'sleep'        => 5,
                'rest'         => 15,
            ],
            'default-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['default', 'webapp-forwarding'],
                'balance'      => 'simple',
                'maxProcesses' => 10,
                'maxJobs'      => 50,
                'memory'       => 256,
                'tries'        => 3,
                'timeout'      => 60,
                'sleep'        => 3,
                'rest'         => 30,
            ],
            'retry-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['retry', 'circuit-breaker'],
                'balance'      => 'simple',
                'maxProcesses' => 5,
                'maxJobs'      => 10,
                'memory'       => 256,
                'tries'        => 1,
                'timeout'      => 180,
                'sleep'        => 10,
                'rest'         => 60,
            ],
        ],

        'staging' => [
            'transaction-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['priority', 'transactions', 'webapp-forwarding'],
                'balance'      => 'auto',
                'maxProcesses' => 15,
                'maxJobs'      => 50,
                'memory'       => 512,
                'tries'        => 3,
                'timeout'      => 300,
                'sleep'        => 3,
                'rest'         => 30,
            ],
            'notification-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['notifications', 'webhooks', 'emails', 'webapp-forwarding'],
                'balance'      => 'auto',
                'maxProcesses' => 8,
                'maxJobs'      => 100,
                'memory'       => 256,
                'tries'        => 3,
                'timeout'      => 120,
                'sleep'        => 5,
                'rest'         => 15,
            ],
            'default-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['default', 'retry', 'webapp-forwarding'],
                'balance'      => 'simple',
                'maxProcesses' => 5,
                'maxJobs'      => 25,
                'memory'       => 256,
                'tries'        => 3,
                'timeout'      => 60,
                'sleep'        => 3,
                'rest'         => 30,
            ],
        ],

        'local' => [
            'transaction-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['transactions', 'priority', 'webapp-forwarding'],
                'balance'      => 'simple',
                'maxProcesses' => 3,
                'maxJobs'      => 10,
                'memory'       => 256,
                'tries'        => 3,
                'timeout'      => 60,
                'sleep'        => 3,
                'rest'         => 30,
            ],
            'default-supervisor' => [
                'connection'   => env('QUEUE_CONNECTION', 'redis'),
                'queue'        => ['default', 'notifications', 'retry', 'webapp-forwarding'],
                'balance'      => 'simple',
                'maxProcesses' => 2,
                'maxJobs'      => 5,
                'memory'       => 128,
                'tries'        => 3,
                'timeout'      => 60,
                'sleep'        => 5,
                'rest'         => 30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Supervisor
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'connection'   => env('QUEUE_CONNECTION', 'redis'),
        'queue'        => ['default', 'webapp-forwarding'],
        'balance'      => 'simple',
        'maxProcesses' => 1,
        'maxJobs'      => 0,
        'memory'       => 128,
        'tries'        => 3,
        'timeout'      => 60,
        'sleep'        => 3,
        'rest'         => 0,
    ],
];
