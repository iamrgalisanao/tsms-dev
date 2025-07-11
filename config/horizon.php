<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    | This is the subdomain where Horizon will be accessible from. When set
    | to null, Horizon will reside under the same domain as the application.
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    */
    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    | These middleware will get attached to every Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Times
    |--------------------------------------------------------------------------
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique wait time threshold set for better flexibility.
    */
    'waits' => [
        'redis:default' => 60,
        'redis:transactions' => 30,
        'redis:notifications' => 90,
        'redis:priority' => 15,
        'database:default' => 60,
        'database:transactions' => 30,
        'database:notifications' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    */
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'failed' => 10080, // 1 week for failed jobs analysis
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    | These jobs will not appear in the Horizon dashboard job list. This
    | setting may be useful to reduce clutter for jobs that run frequently
    | but are not important to monitor in the Horizon dashboard.
    */
    'silenced' => [
        // Add frequent but unimportant jobs here
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    | Here you can configure how many snapshots should be kept to display
    | metrics in Horizon. Snapshots are taken every minute for jobs and
    | every five minutes for queues.
    */
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,    // 24 hours of job metrics
            'queue' => 24,  // 24 hours of queue metrics
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to finish their current job. However, you
    | will not receive the return codes of any terminating processes.
    */
    'fast_termination' => env('HORIZON_FAST_TERMINATION', false),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    */
    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Environment Configurations
    |--------------------------------------------------------------------------
    | Optimized for TSMS high-throughput transaction processing
    | - Production: 1000+ transactions/minute capability
    | - Edge cases: Circuit breakers, retries, dead letter queues
    | - Scalability: Auto-scaling workers, memory management
    */
    'environments' => [
        'production' => [
            // High-priority transaction processing supervisor
            'transaction-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['priority', 'transactions'],
                'balance' => 'auto',
                'maxProcesses' => 25,        // High concurrency for 1000+ TPS
                'maxTime' => 0,              // No time limit for long-running processes
                'maxJobs' => 100,            // Restart workers after 100 jobs (memory management)
                'memory' => 512,             // 512MB per worker
                'tries' => 3,                // Retry failed jobs 3 times
                'timeout' => 300,            // 5 minutes timeout for complex transactions
                'sleep' => 3,                // Sleep 3 seconds when no jobs
                'nice' => 0,                 // Normal process priority
                'rest' => 30,                // Rest 30 seconds between job batches
            ],

            // Notification and callback supervisor
            'notification-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['notifications', 'webhooks', 'emails'],
                'balance' => 'auto',
                'maxProcesses' => 15,        // Medium concurrency for notifications
                'maxTime' => 0,
                'maxJobs' => 200,            // More jobs per worker (lighter processing)
                'memory' => 256,             // Lower memory requirement
                'tries' => 5,                // More retries for notifications (network issues)
                'timeout' => 120,            // 2 minutes for webhook timeouts
                'sleep' => 5,
                'nice' => 5,                 // Lower priority than transactions
                'rest' => 15,
            ],

            // Default supervisor for general tasks
            'default-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['default'],
                'balance' => 'simple',
                'maxProcesses' => 10,
                'maxTime' => 0,
                'maxJobs' => 50,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 3,
                'nice' => 10,                // Lowest priority
                'rest' => 30,
            ],

            // Circuit breaker and retry supervisor for failed jobs
            'retry-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['retry', 'circuit-breaker'],
                'balance' => 'simple',
                'maxProcesses' => 5,         // Limited processes for retries
                'maxTime' => 0,
                'maxJobs' => 10,
                'memory' => 256,
                'tries' => 1,                // Only 1 try for retry queue
                'timeout' => 180,            // Longer timeout for retry logic
                'sleep' => 10,               // Longer sleep for retry queue
                'nice' => 15,                // Lower priority
                'rest' => 60,                // Longer rest between batches
            ],
        ],

        'staging' => [
            'transaction-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['priority', 'transactions'],
                'balance' => 'auto',
                'maxProcesses' => 15,        // Reduced for staging
                'maxTime' => 0,
                'maxJobs' => 50,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 300,
                'sleep' => 3,
                'nice' => 0,
                'rest' => 30,
            ],

            'notification-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['notifications', 'webhooks', 'emails'],
                'balance' => 'auto',
                'maxProcesses' => 8,
                'maxTime' => 0,
                'maxJobs' => 100,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'sleep' => 5,
                'nice' => 5,
                'rest' => 15,
            ],

            'default-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'redis'),
                'queue' => ['default', 'retry'],
                'balance' => 'simple',
                'maxProcesses' => 5,
                'maxTime' => 0,
                'maxJobs' => 25,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 3,
                'nice' => 10,
                'rest' => 30,
            ],
        ],

        'local' => [
            'transaction-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'database'),  // Use database for local dev
                'queue' => ['transactions', 'priority'],
                'balance' => 'simple',
                'maxProcesses' => 3,         // Conservative for development
                'maxTime' => 0,
                'maxJobs' => 10,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 3,
                'nice' => 0,
                'rest' => 30,
            ],

            'default-supervisor' => [
                'connection' => env('QUEUE_CONNECTION', 'database'),
                'queue' => ['default', 'notifications', 'retry'],
                'balance' => 'simple',
                'maxProcesses' => 2,
                'maxTime' => 0,
                'maxJobs' => 5,
                'memory' => 128,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 5,
                'nice' => 0,
                'rest' => 30,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    | These values will be used as defaults for any supervisor that doesn't
    | specify the key. This allows you to configure default values and
    | then override them for specific supervisors or environments.
    */
    'defaults' => [
        'supervisor-1' => [
            'connection' => env('QUEUE_CONNECTION', 'redis'),
            'queue' => ['default'],
            'balance' => 'simple',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'sleep' => 3,
            'nice' => 0,
            'rest' => 0,
        ],
    ],
];
