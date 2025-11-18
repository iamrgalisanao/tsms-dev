<?php

return [
    // Enable by default in development/staging; production can disable via env
    'enabled' => env('WEBAPP_API_ENABLED', true),
    'allowed_ips' => array_filter(array_map('trim', explode(',', env('WEBAPP_API_ALLOWED_IPS', '')))),
    'token_ability' => env('WEBAPP_API_TOKEN_ABILITY', 'webapp:read'),
    'cache_ttl_seconds' => (int) env('WEBAPP_API_CACHE_TTL', 10),
    // Maximum per_page allowed from clients (prevents heavy queries)
    'max_per_page' => (int) env('WEBAPP_API_MAX_PER_PAGE', 100),
    // Rate limit per minute for webapp tokens
    'rate_limit_per_minute' => (int) env('WEBAPP_API_RATE_LIMIT_PER_MINUTE', 120),
    // Static tokens: for simple integrations you may supply one or more
    // static bearer tokens via env (comma-separated). These are intended
    // for short-term/staging use only — prefer Sanctum tokens in production.
    // Default is false to avoid accidental insecure usage in production.
    'use_static_tokens' => env('WEBAPP_API_USE_STATIC_TOKENS', false),
    'static_tokens' => array_filter(array_map('trim', explode(',', env('WEBAPP_API_STATIC_TOKENS', '')))),
];
