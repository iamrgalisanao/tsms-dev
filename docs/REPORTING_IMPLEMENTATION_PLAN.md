# Reporting Implementation Plan

This file is a concise implementation plan for adding read-only reporting backed by summary tables (start with `transactions_hourly` and `transactions_daily`). It lists practical changes, prioritized rollout, validation tests, and monitoring/security notes.

1) Practical changes (high level)
- Add `reporting` DB connection in `config/database.php` and `.env` entries: `DB_REPORTING_HOST`, `DB_REPORTING_DATABASE`, `DB_REPORTING_USERNAME`, `DB_REPORTING_PASSWORD`, `DB_REPORTING_PORT`.
- Create summary tables (begin with `transactions_hourly` and `transactions_daily`) via migrations or SQL scripts.
- Add a Laravel Command (scheduler) and queue job to incrementally refresh summary tables (idempotent upsert per bucket).
- Update controllers/endpoints that serve aggregated data to use `DB::connection('reporting')`.
- Add caching (Redis) for heavy endpoints with TTLs matching freshness SLA.
- Create a read-only `reporting` DB user and restrict network access to webapp hosts only.

2) Prioritized rollout
- Phase 1 (P0): Implement `transactions_hourly` and `transactions_daily` summary tables + refresh job; wire `commercial` hourly/daily endpoints to reporting connection.
- Phase 2 (P1): Wire `finance.reports` (monthly) and export controllers to use `transactions_monthly`/daily. Add reconciliation summary and SOA sources.
- Phase 3 (P2): Convert remaining endpoints iteratively, add caching, and reduce forwarding usage (canary and validation).

3) Refresh & scheduling
- Hourly summary: schedule every 5 minutes; compute a sliding window of the last 3 hours and upsert per (tenant_id, hour).
- Daily summary: schedule hourly for last 48 hours, and nightly finalize previous day.
- Use metadata table `summary_jobs_meta` (summary_table, last_processed_bucket_utc, last_run_at, status) to drive incremental runs.

4) Idempotency & late-arrival handling
- Use upsert semantics (Postgres `ON CONFLICT` or MySQL `ON DUPLICATE KEY UPDATE`) keyed by (tenant_id, bucket_start[, terminal_id]).
- Recompute recent buckets (safety margin) to handle late-arriving transactions and updates.
- Provide a backfill CLI command for historical recompute.

5) Validation & tests (must-have)
- Functional parity tests: compare reporting aggregates vs raw DB aggregations for sample tenants/time ranges.
- Permission test: verify `reporting` user cannot perform writes.
- Performance smoke tests: measure p95/p99 for typical aggregated queries; ensure under SLA.
- Export tests: Excel exports generated from summaries must match expected totals.

6) Monitoring & alerts
- Track metrics: `summary.refresh.last_success{table}`, `summary.refresh.duration{table}`, `summary.drift.alerts`, `replica.lag_seconds`.
- Alert if `last_success` > threshold (e.g., 15 minutes) or if drift exceeds tolerance.

7) Security & privacy
- Use least-privilege DB user for reporting (SELECT only).
- Limit network access to the read-replica to webapp hosts only.
- Mask or remove PII from summary tables.

8) Acceptance criteria
- Aggregates in `transactions_hourly` match raw computations for sampled buckets within tolerance.
- Reporting endpoints respond within p95 latency target and do not impact OLTP performance.
- `reporting` user passes write-permission tests.

9) Rollback & fallback
- Keep forwarding enabled during canary validation until parity is confirmed.
- If issues found, revert endpoints to raw DB queries and iterate on fixes in summary generation.

---

If you want, I can scaffold the migrations and the Laravel Command for incremental refresh next. Tell me whether your primary DB is Postgres or MySQL and I will tailor the SQL/migrations accordingly.
