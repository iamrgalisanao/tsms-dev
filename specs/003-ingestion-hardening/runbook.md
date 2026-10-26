# Ingestion Hardening Runbook

Covers the operator-facing behavior added by feature `003-ingestion-hardening` (specs/003-ingestion-hardening/). Related: `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`, `docs/LICENSE_ROUTE_CLASSIFICATION.md`.

## 1. Batch endpoint retired

`POST /api/v1/transactions/batch` returns `410` with `error_code: ENDPOINT_RETIRED` and `replacement: /api/v1/transactions/official`. It creates no transaction, intake row or job. Each refused call writes one `Log::warning('batch endpoint retired: request refused')` with `terminal_id`, `tenant_id`, `correlation_id`; search logs for that message to find any provider still calling it. The route stays behind `auth:sanctum`, `abilities:transaction:create`, `throttle:pos-ingestion` and `license.valid` (an unlicensed deployment in enforce mode sees the license error first). The flag-gated legacy route (`TSMS_ENABLE_LEGACY_API=true`) returns the same 410.

## 2. Ingest outcome classification (TSMS-07)

`TransactionIngestService` no longer uses `INSERT IGNORE`. A duplicate-key error is classified after the database transaction closes, using lookups that work on any schema:

| Scenario | Ingest result | Intake `processing_status` | `last_error_code` |
| --- | --- | --- | --- |
| New sale | accepted | PROCESSED | — |
| Same tenant resubmits same `transaction_id` | already_processed | PROCESSED | — |
| Same terminal/receipt/business date, new `transaction_id` | duplicate | DUPLICATE | `duplicate_receipt_conflict` |
| `transaction_id` already used by another tenant | failed | FAILED_PERMANENT | `cross_tenant_transaction_id_collision` |
| Any other unique index | failed | FAILED_PERMANENT | `unique_constraint_violation` (index name in details) |
| Missing `net_sales` (direct ingest call) | failed | FAILED_PERMANENT | `invalid_payload` |

- The message `insert ignored but no existing transaction found` is no longer produced. Existing FAILED_PERMANENT rows with that message (6,572 on staging, Apr 28 – Oct 25, 2026) are **not** reclassified; that is a separate, approved data task.
- A cross-tenant collision logs a warning with both tenant IDs and increments `ingest.cross_tenant_collision_count`; the other tenant's row is never returned.
- The app's MySQL connection runs non-strict (`'strict' => false`), so over-length values are still truncated exactly as before. Only duplicate keys and hard errors (e.g. NULL into NOT NULL) now surface.
- Ingest logs carry references only (`tenant_id`, `terminal_id`, `transaction_id`, `receipt_no`, `submission_uuid`, `correlation_id`), never payloads, rows or exception messages with SQL bindings. The intake `trace_id` is passed into ingest logs, so one `correlation_id` traces a submission from request to recorded sale.

## 3. Timestamp checks (TSMS-08)

Configured by `ingestion.timestamps.*` (`config/ingestion.php`).

| Env | Default | Meaning |
| --- | --- | --- |
| `INGESTION_TS_MODE` | `observe` | `observe`: accept as today, count would-be rejections. `enforce`: reject with 422. |
| `INGESTION_TS_EARLIEST` | `2020-01-01` | Earlier dates are invalid. Empty or unparseable = no bound. |
| `INGESTION_TS_FUTURE_TOLERANCE_MINUTES` | `1440` | Later than received time + tolerance = future. |
| `INGESTION_TS_LATE_THRESHOLD_MINUTES` | `1440` | Older than this = late (flagged, never rejected). |

- Codes: `INVALID_TRANSACTION_TIMESTAMP` (zero or impossible date such as `0000-00-00` or Feb 30, or before earliest) and `FUTURE_TRANSACTION_TIMESTAMP`. In enforce mode they use the existing 422 body shape; `HARDWARE_ID_MISMATCH` (403) and `STRUCTURAL_VALIDATION_FAILURE` keep precedence.
- Metrics: `intake.timestamp_would_reject.{code}` (observe), `intake.late_count`, `intake.arrival_lag_seconds`, `intake.timestamp_check_error`.
- **Fail-open:** if the check hits an infrastructure error (e.g. the provider lookup fails on the database), the sale is accepted, a warning is logged with the exception class, and `intake.timestamp_check_error` increments. Only genuine parse failures count as invalid.
- Observe-mode metrics count again when the same submission is resent.
- Enforce-mode precedence: the timestamp check runs during structural validation, before the idempotency lookup. After switching to `enforce`, resending a submission that was accepted in observe mode with a bad date returns 422 instead of its stored outcome (no rejection row or counter is added, because the submission_uuid already exists).
- Staging evidence (60 days to 2026-10-26): 0 of 385,258 sales before 2020; 21 more than 24h after arrival; 90,208 (23%) more than one day late. **Review `intake.timestamp_would_reject.*` on staging for at least a week before switching to `enforce`.**
- In observe mode a zero-date payload is still accepted at intake, but the ingest job now ends it `FAILED_PERMANENT` / `ingest_failed` instead of storing coerced values.

## 4. Intake rejection alerts (TSMS-03)

Scheduled command `intake-rejection-monitor` (`routes/console.php`): every 15 minutes, `withoutOverlapping()`, `onOneServer()`, only when alerts are enabled. Implementation: `app/Services/Monitoring/IntakeRejectionMonitor.php`, notification `app/Notifications/IntakeRejectionAlert.php`.

| Env | Default | Meaning |
| --- | --- | --- |
| `INGESTION_REJECTION_ALERTS_ENABLED` | `true` | Master switch |
| `INGESTION_REJECTION_ALERT_WINDOW_MINUTES` | `60` | Spike window |
| `INGESTION_REJECTION_ALERT_THRESHOLD` | `100` | Rejections per terminal + code in the window |
| `INGESTION_REJECTION_ALERT_NO_ACCEPT_HOURS` | `24` | "Submitting but nothing accepted" window |
| `INGESTION_REJECTION_ALERT_COOLDOWN_MINUTES` | `360` | Minimum gap between alerts per terminal + reason |

Checks:
1. **Rejection spike:** a terminal with at least `threshold` rejected intakes for one error code within the window.
2. **No accepted sales:** a terminal with rejected intakes and zero non-rejected intakes in the last `no_accept_hours`. This is the pattern behind the Aug–Oct 2026 French Baker rejections. It complements the tenant-inactivity monitor, which only alerts when submissions stop.

Behavior:
- Both queries read `transaction_intake` only (indexes `idx_intake_status_received`, `transaction_intake_received_at_index`) with a 5-second `MAX_EXECUTION_TIME` cap on MySQL.
- Delivery: admin users via `NotificationService::notifyAdmins()` (same recipients and channels as other operational alerts), plus a `SystemLog` row (type `intake_rejection`) and a `Log::warning` with references only.
- The cooldown is spent only after a successful send; one failed alert does not block the others or silence itself for the cooldown.
- Every failure inside the check is caught and logged; the scheduler never receives an exception.
- Per-rejection counters: `intake.rejected.code.{code}`, `intake.rejected.tenant.{tenant_id}`, `intake.rejected.terminal.{terminal_id}.{code}` (no TTL; bounded by terminals × codes).
- Not covered (follow-ups): `BATCH_LIMIT_EXCEEDED` and `SUBMISSION_ALREADY_REJECTED` rejections, which are not persisted to `transaction_intake`, and spikes of processing-stage FAILED_PERMANENT.

## 5. Operations

- **Changing any setting above** with cached config: `php artisan config:cache`, reload PHP-FPM, then `php artisan horizon:terminate` so workers pick it up.
- **Disable alerts:** `INGESTION_REJECTION_ALERTS_ENABLED=false`, then the step above.
- **During DDL deploys:** pause the scheduler (or disable alerts) while migrations add indexes to `transaction_intake`. Long reads can hold metadata locks, as in the Aug 2026 staging incident.
- **Check the schedule:** `php artisan schedule:list | grep intake-rejection-monitor`.
