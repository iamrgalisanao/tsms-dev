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
        'auth' => [
            'attempts' => env('RATE_LIMIT_AUTH_ATTEMPTS', 5),
            'decay_minutes' => env('RATE_LIMIT_AUTH_DECAY_MINUTES', 15),
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
];
