<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        // Redis queue connection: required because Horizon supervisors in
        // `config/horizon.php` reference the 'redis' connection.
        // Tune `retry_after` so it is safely greater than the largest
        // Horizon supervisor `timeout` value to avoid duplicate processing.
        'redis' => [
            'driver' => 'redis',
            // This references the connection name defined in config/redis.php
            // (e.g. 'default' or a dedicated 'horizon' connection). Laravel
            // queue worker will use the named Redis connection when processing.
            'connection' => env('QUEUE_REDIS_CONNECTION', 'default'),
            // The default queue name for Redis connections. Horizon expects a
            // 'queue' key to be present when constructing its RedisQueue.
            // Use QUEUE_REDIS_QUEUE to override if needed (e.g. 'default' or
            // 'transaction-processing').
            'queue' => env('QUEUE_REDIS_QUEUE', 'default'),
            // retry_after must be greater than the largest job timeout used
            // by Horizon supervisors (120s in current config). We use a
            // conservative default to avoid early re-queueing.
            'retry_after' => (int) env('QUEUE_REDIS_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];