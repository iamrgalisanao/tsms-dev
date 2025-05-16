<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration for the transaction retry system.
    |
    */

    // Maximum number of retry attempts
    'max_attempts' => env('RETRY_MAX_ATTEMPTS', 3),

    // Initial delay between retries in seconds
    'delay' => env('RETRY_DELAY', 60),

    // Backoff multiplier for exponential backoff
    'backoff_multiplier' => env('RETRY_BACKOFF_MULTIPLIER', 2),

    // Circuit breaker threshold for consecutive failures
    'circuit_breaker_threshold' => env('CIRCUIT_BREAKER_THRESHOLD', 5),

    // Circuit breaker reset timeout in seconds
    'circuit_breaker_timeout' => env('CIRCUIT_BREAKER_TIMEOUT', 300),
];
