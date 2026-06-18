# Reporting DB Runbook

Quick runbook for provisioning a reporting/read-only DB connection and basic operational tasks.

1) Env & config
- Add to `.env` (example):

```
DB_REPORTING_CONNECTION=mysql
DB_REPORTING_HOST=reporting-db.example
DB_REPORTING_PORT=3306
DB_REPORTING_DATABASE=tsms_reporting
DB_REPORTING_USERNAME=reporting
DB_REPORTING_PASSWORD=<secure_password>
```

- Add `reporting` connection in `config/database.php` that reads env vars and uses the same driver as primary DB.

2) Create read-only DB user (example templates)
- Postgres (example): create `reporting` role with SELECT only on reporting schema and tables.
- MySQL (example): create user `reporting`@'webapp-host' IDENTIFIED BY 'pwd'; GRANT SELECT ON tsms_reporting.* TO `reporting`@'webapp-host'; FLUSH PRIVILEGES;

3) Migrations & initial refresh
- Deploy migrations that create `transactions_hourly` and `transactions_daily` (or materialized views for Postgres).
- Run the initial refresh/backfill for historical windows (this may take time; run during low traffic).

4) Scheduler & jobs
- Add Laravel scheduler entry in `app/Console/Kernel.php` to run the refresh command every 5 minutes for hourly and hourly/daily schedules.
- Use `php artisan schedule:run` via systemd or cron on the webapp host.

5) Sample commands
- Run incremental refresh manually:

```bash
php artisan reporting:refresh transactions_hourly --from="2025-11-17T00:00:00Z" --to="2025-11-17T23:00:00Z"
```

- Backfill full date range (throttled):

```bash
php artisan reporting:backfill transactions_daily --from=2025-01-01 --to=2025-11-16
```

6) Monitoring & metadata
- The refresh command should update `summary_jobs_meta` table with `last_success`, `last_run_at`, `duration`, and `rows_processed`.
- Display `last_success` on internal Ops dashboard and create alerts if `last_success` older than threshold.

7) Trouble-shooting
- If aggregates are off for a tenant/date:
  1. Run `reporting:refresh` for the affected bucket(s) and check logs.
  2. Run raw aggregation queries on primary DB for the same bucket and compare results.
  3. If reconciliation mismatch persists, run a targeted backfill for that tenant/date and compare again.

8) Security & rotation
- Rotate `reporting` DB user password as part of regular secret rotation.
- Ensure `.env` with `DB_REPORTING_PASSWORD` is stored securely and not committed.

9) Operational checklist before decommissioning forwarding
- Implement parity tests for several tenants/time windows and keep forwarding enabled during canary validation.
- Once parity confirmed and monitored for 48–72 hours, remove forwarding for read-only reporting consumers.

10) Contact list
- Add the infra/DB owner's Slack/email and the reporting job owner in this document header for escalation.
