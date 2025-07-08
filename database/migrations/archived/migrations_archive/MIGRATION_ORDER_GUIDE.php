<?php
// This file lists the correct migration order for your Laravel project as of 2025-07-04.
// Rename your migration files to match this order if needed, so that dependencies are respected and migrations run without error.

// 1. Core system tables (users, cache, jobs, etc.)
// 2. Tenants
// 3. POS Terminals
// 4. Transactions
// 5. Integration Logs (references tenants, pos_terminals)
// 6. Webhook Logs (if references core tables)
// 7. Other system/feature tables
// 8. Updates/Alters (must come after the table they modify)

return [
    '0001_01_01_000000_create_users_table.php',
    '0001_01_01_000001_create_cache_table.php',
    '0001_01_01_000002_create_jobs_table.php',
    '2025_07_04_000000_create_users_table.php',
    '2025_07_04_000001_create_cache_table.php',
    '2025_07_04_000002_create_jobs_table.php',
    '2025_07_04_000003_create_cache_locks_table.php',
    '2025_07_04_000004_create_failed_jobs_table.php',
    '2025_07_04_000005_create_job_batches_table.php',
    '2025_07_04_000006_create_password_reset_tokens_table.php',
    '2025_07_04_000007_create_sessions_table.php',
    '2025_07_04_000008_create_tenants_table.php',
    '2025_07_04_000009_create_migrations_table.php',
    '2025_07_04_000010_create_failed_jobs_table.php',
    '2025_07_04_000011_create_password_reset_tokens_table.php',
    '2025_07_04_000012_create_job_batches_table.php',
    '2025_07_04_000013_create_cache_locks_table.php',
    '2025_07_04_000014_create_sessions_table.php',
    '2025_07_04_000015_create_migrations_table.php',
    '2025_07_04_000016_create_failed_jobs_table.php',
    '2025_07_04_000017_create_users_table.php',
    '2025_07_04_000018_create_pos_terminals_table.php',
    '2025_07_04_000019_create_transactions_table.php',
    '2024_01_01_000001_create_integration_logs_table.php',
    '2024_01_02_create_webhook_logs_table.php',
    '2024_05_24_create_system_logs_table.php',
    '2024_05_26_create_audit_logs_table.php',
    '2024_05_30_000001_update_system_logs_table.php',
    // ...other feature and update migrations...
];