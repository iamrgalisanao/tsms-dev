<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transaction Pruning & Retention
    |--------------------------------------------------------------------------
    | Settings to prune stale or failed transaction records while keeping audit
    | value. PENDING max age protects against stuck jobs. FAILED retention keeps
    | recent diagnostics.
    */
    'transactions' => [
        'prune_failed_after_days' => (int) env('TX_PRUNE_FAILED_AFTER_DAYS', 14),
        'prune_pending_after_minutes' => (int) env('TX_PRUNE_PENDING_AFTER_MIN', 180), // treat as stale
        'enable_pruning' => (bool) env('TX_ENABLE_PRUNING', true),
        'log_channel' => env('TX_PRUNE_LOG_CHANNEL', 'single'),
        // Watchdog settings for stuck / slow transactions
        'watchdog' => [
            'enabled' => (bool) env('TX_WATCHDOG_ENABLED', true),
            // If a transaction stays PENDING this long, mark as FAILED (terminal timeout)
            'max_pending_minutes' => (int) env('TX_WATCHDOG_MAX_PENDING_MIN', 60),
            // Re-dispatch (requeue) PENDING+QUEUED transactions older than this age
            'requeue_after_minutes' => (int) env('TX_WATCHDOG_REQUEUE_AFTER_MIN', 10),
            // Maximum re-dispatch attempts before forcing failure (uses transaction.job_attempts)
            'max_requeue_attempts' => (int) env('TX_WATCHDOG_MAX_REQUEUE_ATTEMPTS', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Tuning
    |--------------------------------------------------------------------------
    | Adjustable validation parameters. The future timestamp tolerance allows
    | acceptance of transactions that are slightly ahead of server time due to
    | POS clock drift. Set to 0 (default) in production for strict behavior.
    */
    'validation' => [
        'strict_mode' => env('TSMS_VALIDATION_STRICT_MODE', false),
        'net_includes_vat' => env('TSMS_NET_INCLUDES_VAT', true),
        'strict_customer_code_binding' => (bool) env('TSMS_STRICT_CUSTOMER_CODE_BINDING', false),
        'enable_computation_validation' => (bool) env('TSMS_ENABLE_COMPUTATION_VALIDATION', env('APP_ENV') === 'testing'),
        'max_vat_difference' => env('TSMS_MAX_VAT_DIFFERENCE', 0.02),
        'max_rounding_difference' => env('TSMS_MAX_ROUNDING_DIFFERENCE', 0.05),
        'future_timestamp_tolerance_seconds' => (int) env('TSMS_FUTURE_TIMESTAMP_TOLERANCE_SECONDS', 0),
    ],

    'testing' => [
        'capture_only' => (bool) env('TSMS_TESTING_CAPTURE_ONLY', false),
        'allow_capture_only_in_production' => (bool) env('TSMS_ALLOW_CAPTURE_ONLY_IN_PROD', false),
    ],

    'reporting' => [
        'exclude_voids_from_totals' => (bool) env('TSMS_REPORTING_EXCLUDE_VOIDS', true),
    ],

    'transaction_logs' => [
        'max_date_range_days' => (int) env('TSMS_TRANSACTION_LOGS_MAX_RANGE_DAYS', 31),
        'max_per_page' => (int) env('TSMS_TRANSACTION_LOGS_MAX_PER_PAGE', 1000),
        'slow_query_threshold_ms' => (int) env('TSMS_TRANSACTION_LOGS_SLOW_QUERY_MS', 3000),
        'timezone' => env('TSMS_TRANSACTION_LOGS_TIMEZONE', 'Asia/Manila'),
    ],

    'dlq' => [
        'alert_threshold' => (int) env('TSMS_DLQ_ALERT_THRESHOLD', 10),
    ],

    'rollout' => [
        'pilot_tenants' => array_filter(explode(',', env('TSMS_PILOT_TENANTS', ''))),
    ],

    'intake' => [
        // Default provider timezone used only when a provider is configured to
        // submit local timestamps using a UTC-looking format.
        'provider_timezone' => env('TSMS_INTAKE_PROVIDER_TIMEZONE', 'Asia/Manila'),
        // true_utc: provider timestamps are real UTC.
        // local_time_with_z: provider sends local wall-clock time in Y-m-dTH:i:sZ.
        'timestamp_mode' => env('TSMS_INTAKE_TIMESTAMP_MODE', 'true_utc'),
        'backpressure' => [
            'enabled' => (bool) env('TSMS_INTAKE_BACKPRESSURE_ENABLED', true),
            'mode' => env('TSMS_INTAKE_BACKPRESSURE_MODE', 'observe'), // observe|enforce
            'max_queue_depth' => (int) env('TSMS_INTAKE_MAX_QUEUE_DEPTH', 5000),
            'retry_after_seconds' => (int) env('TSMS_INTAKE_BACKPRESSURE_RETRY_AFTER_SECONDS', 60),
            'reject_status' => (int) env('TSMS_INTAKE_BACKPRESSURE_REJECT_STATUS', 429),
        ],
        // Cheap, pre-validation guards applied before expensive structural
        // validation, checksum verification, or DB writes. See
        // IngestionPayloadSizeMiddleware (payload bytes) and
        // TransactionIntakeService/TransactionController (batch count).
        'max_payload_bytes' => (int) env('TSMS_INTAKE_MAX_PAYLOAD_BYTES', 2097152), // 2 MB
        'max_batch_count' => (int) env('TSMS_INTAKE_MAX_BATCH_COUNT', 500),
        'shard_count' => (int) env('TSMS_INTAKE_SHARD_COUNT', 8),
        'vip_shard' => env('TSMS_INTAKE_VIP_SHARD', 'vip'),
    ],

    'processing' => [
        'shard_count' => (int) env('TSMS_PROCESSING_SHARD_COUNT', env('TSMS_INTAKE_SHARD_COUNT', 8)),
    ],

    'circuit_breaker' => [
        'enabled' => (bool) env('TSMS_CIRCUIT_BREAKER_ENABLED', true),
        'redis_connection' => env('TSMS_CIRCUIT_BREAKER_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('TSMS_CIRCUIT_BREAKER_KEY_PREFIX', 'tsms:circuit-breaker:'),
        'failure_threshold' => (int) env('TSMS_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
        'reset_timeout_seconds' => (int) env('TSMS_CIRCUIT_BREAKER_RESET_TIMEOUT_SECONDS', 60),
        'state_ttl_seconds' => (int) env('TSMS_CIRCUIT_BREAKER_STATE_TTL_SECONDS', 3600),
    ],

    // Metrics distribution store (WU2, T053 foundation): bounded Redis
    // structures backing App\Support\Metrics::sample()/percentile(), kept
    // separate from the Cache-backed counter/gauge store used by
    // incr/decr/timing/bucket/get/snapshot (see
    // App\Support\MetricStores\RedisMetricDistributionStore).
    //
    // sample_cap: max most-recent samples retained per metric+dimension
    // key (1000). Percentile reads are approximate by design (nearest-rank
    // over whatever recent window is retained); 1000 is large enough for a
    // stable p99 on typical request-rate metrics while keeping a ZRANGE(0,-1)
    // read (used to compute percentiles) cheap for an operator-facing
    // endpoint that is polled infrequently, not per-request.
    // sample_ttl_seconds: secondary bound — an abandoned per-combination
    // key (e.g. a route that stops receiving traffic) expires on its own
    // instead of persisting forever at its last-known cap size.
    // cardinality_budget: max distinct dimension-combination keys tracked
    // per metric name (200). WU2 defines this budget and its enforcement;
    // WU2 itself has no call sites (only the allowlisted dimensions below
    // exist for future WU4/WU7 use), so 200 is a generous ceiling sized for
    // route/shard combinations at ~100-tenant scale, not tenant-cardinality
    // (tenant_id is deliberately not an allowed dimension here).
    // cardinality_ttl_seconds: TTL on the per-metric combination-tracking
    // set itself; refreshed on every admitted combination, so it only
    // clears once a metric name receives no new-or-repeat traffic for the
    // whole window (documented, deliberate: this can under-expire relative
    // to any single idle combination, but the failure direction is always
    // toward rejecting new combinations, never toward unbounded growth).
    // allowed_dimensions: fixed allowlist — no arbitrary caller-supplied
    // dimension keys. tenant_id is explicitly excluded (WU4/WU7 cardinality
    // budget concern); callers must use only these names.
    'metrics' => [
        'distribution' => [
            'redis_connection' => env('TSMS_METRICS_REDIS_CONNECTION', 'default'),
            'key_prefix' => env('TSMS_METRICS_KEY_PREFIX', 'metrics:dist:'),
            'sample_cap' => (int) env('TSMS_METRICS_SAMPLE_CAP', 1000),
            'sample_ttl_seconds' => (int) env('TSMS_METRICS_SAMPLE_TTL_SECONDS', 3600),
            'cardinality_budget' => (int) env('TSMS_METRICS_CARDINALITY_BUDGET', 200),
            'cardinality_ttl_seconds' => (int) env('TSMS_METRICS_CARDINALITY_TTL_SECONDS', 3600),
            'allowed_dimensions' => ['route', 'shard'],
        ],

        // Bounded tenant/terminal "top-N talkers" ranking (WU4, T053
        // remainder, Architecture Invariant 5 — bounded cardinality).
        // Backed by App\Services\SkewRankingService: one Redis sorted set
        // per dimension (tenant/terminal) per fixed time window, member =
        // tenant/terminal ID, score = request count within that window.
        // Deliberately separate from the unbounded per-tenant
        // `tenant.{id}.intake_count` Cache counter already written by
        // TransactionIntakeService (WU2 finding) — that counter answers
        // "what is tenant X's count", this structure answers "who are the
        // top N tenants/terminals right now" without ever holding more than
        // member_cap members at a time.
        // window_seconds: ranking window width (5 minutes) — long enough to
        // smooth single-request noise, short enough that "current top
        // talkers" stays operationally meaningful.
        // member_cap: max distinct tenant/terminal IDs tracked per window
        // (500) before the lowest-ranked (least active) member is evicted
        // on the next insert. Sized generously above this feature's
        // ~100-tenant target (and a plausible multiple of that many active
        // terminals) so eviction only engages under genuine, unexpected
        // fan-out — while still bounding worst-case ZSET size to a few
        // hundred small entries, never unbounded per-terminal growth.
        // ttl_seconds: on the whole per-window key (10 minutes — 2x
        // window_seconds), so a finished window's key expires on its own
        // shortly after it stops being "current" instead of persisting
        // forever at its last-known size.
        // max_top_n: hard ceiling on any single top-N read (100), so a
        // caller (WU7's future observability endpoint) cannot request an
        // unbounded read even though member_cap already bounds the
        // underlying structure.
        'skew' => [
            'redis_connection' => env('TSMS_SKEW_REDIS_CONNECTION', 'default'),
            'key_prefix' => env('TSMS_SKEW_KEY_PREFIX', 'metrics:skew:'),
            'window_seconds' => (int) env('TSMS_SKEW_WINDOW_SECONDS', 300),
            'member_cap' => (int) env('TSMS_SKEW_MEMBER_CAP', 500),
            'ttl_seconds' => (int) env('TSMS_SKEW_TTL_SECONDS', 600),
            'max_top_n' => (int) env('TSMS_SKEW_MAX_TOP_N', 100),
        ],
    ],

    // Fairness (T044): Redis fixed-window INCR+EXPIRE admission limits, per
    // scope (global/tenant/terminal), consumed by App\Services\IngestionFairnessService.
    // A single limit set applies uniformly to every tenant/terminal —
    // per-tenant tier overrides are explicitly deferred/out of scope for
    // this feature (see specs/001-100-tenant-resilience/plan.md's
    // "Fairness Architecture" subsection, point 7). Defaults below are
    // sized for the feature's ~100-tenant target at window_seconds=60:
    // tenant=200/min (~3.3 req/s per tenant), terminal=50/min (a single
    // terminal is not expected to sustain much more than ~1 req/s), and
    // global=10000/min as a generous system-wide ceiling that is well
    // above 100 tenants each bursting to their own limit simultaneously
    // (100 * 200 = 20000 theoretical max, so the global limit is intended
    // to smooth bursts rather than be a hard sum-of-tenants cap).
    'fairness' => [
        'redis_connection' => env('TSMS_FAIRNESS_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('TSMS_FAIRNESS_KEY_PREFIX', 'fairness:'),
        'window_seconds' => (int) env('TSMS_FAIRNESS_WINDOW_SECONDS', 60),
        'global' => [
            'limit' => (int) env('TSMS_FAIRNESS_GLOBAL_LIMIT', 10000),
        ],
        'tenant' => [
            'limit' => (int) env('TSMS_FAIRNESS_TENANT_LIMIT', 200),
        ],
        'terminal' => [
            'limit' => (int) env('TSMS_FAIRNESS_TERMINAL_LIMIT', 50),
        ],
    ],

    // Tenant fairness override (WU5): TTL-bounded, incident-response
    // control for throttling ONE specific tenant during a live drill/
    // incident, backed by App\Services\TenantFairnessOverrideService and
    // consumed by IngestionFairnessService::checkTenantOverride() before
    // its own global-limit decision. Deliberately NOT the same thing as
    // the fairness config above's deferred, persistent tenant-tier policy
    // system (see specs/001-100-tenant-resilience/plan.md's "Fairness
    // Architecture" subsection, point 7, and WU5's "Reconciliation with
    // Fairness Architecture point 7" note): this mechanism has no tier
    // concept, no persistent policy schema, and every override expires by
    // design (Architecture Invariant 7 — this max-TTL value is owned and
    // enforced by WU5 in this same commit, not introduced later by WU8's
    // alert/config work).
    //
    // max_ttl_seconds: hard ceiling on any single override's TTL (4 hours).
    // Long enough to cover one incident-response shift or a full staging
    // drill without requiring the operator to re-issue the command
    // mid-incident, while still guaranteeing an override can never be
    // forgotten and left throttling/blocking a tenant indefinitely — a
    // request for a longer TTL is rejected outright (not silently
    // clamped), so the operator always knows the real expiry they are
    // getting rather than assuming a longer one that was quietly shortened.
    'tenant_throttle' => [
        'redis_connection' => env('TSMS_TENANT_THROTTLE_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('TSMS_TENANT_THROTTLE_KEY_PREFIX', 'fairness:override:'),
        'max_ttl_seconds' => (int) env('TSMS_TENANT_THROTTLE_MAX_TTL_SECONDS', 14400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terminals: Idle Monitor
    |--------------------------------------------------------------------------
    | Controls the background scheduler that detects idle POS terminals based on
    | last_seen_at and per-terminal heartbeat thresholds. Starts in log-only
    | mode. When enabled later, notifications can be turned on behind flags.
    */
    'terminals' => [
        'idle_monitor' => [
            // Master toggle for the scheduler; default off (observation opt-in)
            'enabled' => (bool) env('TSMS_IDLE_MONITOR_ENABLED', false),
            // How often the job runs (minutes); used to build cron expression
            'scan_interval_minutes' => (int) env('TSMS_IDLE_MONITOR_SCAN_MIN', 5),
            // Which activity source determines idleness: last_seen (health) or last_sale (business)
            'activity_basis' => env('TSMS_IDLE_MONITOR_ACTIVITY_BASIS', 'last_seen'), // last_seen|last_sale|composite
            // Default idle window if terminal has no heartbeat_threshold set
            'idle_after_seconds_default' => (int) env('TSMS_IDLE_MONITOR_IDLE_DEFAULT', 3600),
            // Idle threshold multiplier relative to heartbeat_threshold
            'multiplier_of_heartbeat' => (int) env('TSMS_IDLE_MONITOR_MULTIPLIER', 3),
            // Prevent duplicate idle logs/alerts within this TTL
            'dedupe_ttl_seconds' => (int) env('TSMS_IDLE_MONITOR_DEDUPE_TTL', 1800),
            // Optional notifications (kept disabled until validated in logs)
            'notify' => [
                'enabled' => (bool) env('TSMS_IDLE_MONITOR_NOTIFY_ENABLED', false),
                // e.g., ["webapp", "mail", "database"] — not used until enabled
                'channels' => explode(',', env('TSMS_IDLE_MONITOR_NOTIFY_CHANNELS', '')),
            ],
            // Optional per-tenant summaries into AuditLog (lightweight)
            'per_tenant_summary' => [
                'enabled' => (bool) env('TSMS_IDLE_MONITOR_TENANT_SUMMARY', false),
                // When true, only write entries for tenants with any activity (idle or recovered)
                'only_nonzero' => (bool) env('TSMS_IDLE_MONITOR_TENANT_SUMMARY_ONLY_NONZERO', true),
            ],
            // Summary details: optionally include a compact list of changed terminals in each run
            'summary_details' => [
                'include_terminals' => (bool) env('TSMS_IDLE_MONITOR_SUMMARY_TERMINALS', true),
                'terminals_cap' => (int) env('TSMS_IDLE_MONITOR_SUMMARY_TERMINALS_CAP', 25),
                // Optionally include a "currently idle" compact list and count
                'include_currently_idle' => (bool) env('TSMS_IDLE_MONITOR_SUMMARY_CURRENTLY_IDLE', false),
                'currently_idle_cap' => (int) env('TSMS_IDLE_MONITOR_SUMMARY_CURRENTLY_IDLE_CAP', 25),
            ],
        ],
    ],
];
