<?php

return [
    // Enable by default in development/staging; production can disable via env
    'enabled' => env('WEBAPP_API_ENABLED', true),
    'allowed_ips' => array_filter(array_map('trim', explode(',', env('WEBAPP_API_ALLOWED_IPS', '')))),
    'token_ability' => env('WEBAPP_API_TOKEN_ABILITY', 'webapp:read'),
    'cache_ttl_seconds' => (int) env('WEBAPP_API_CACHE_TTL', 10),
    // Static tokens: for simple integrations you may supply one or more
    // static bearer tokens via env (comma-separated). These are intended
    // for short-term/staging use only — prefer Sanctum tokens in production.
    'use_static_tokens' => env('WEBAPP_API_USE_STATIC_TOKENS', true),
    'static_tokens' => array_filter(array_map('trim', explode(',', env('WEBAPP_API_STATIC_TOKENS', '')))),
];
