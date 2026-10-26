# Data Model: Ingestion Hardening

No schema changes. This feature changes how existing records are written and classified.

## transaction_intake (existing)

| Field | Change |
| --- | --- |
| `intake_status` | Unchanged values. New rejections from US5 use `REJECTED`. |
| `last_error_code` | New values: `INVALID_TRANSACTION_TIMESTAMP`, `FUTURE_TRANSACTION_TIMESTAMP` (intake rejections); `cross_tenant_transaction_id_collision`, `unique_constraint_violation` (processing failures). Existing values unchanged. |
| `processing_status` | Mapping unchanged: `already_processed` → PROCESSED, `duplicate_receipt_conflict` → DUPLICATE, `failed` → FAILED_PERMANENT. US2 changes which ingest outcome is produced, not the mapping. |

## transactions (existing)

Unchanged schema. Unique rules relied on: `ux_transactions_transaction_id (transaction_id)`, `ux_tx_tenant_terminal_receipt_date_unique (tenant_id, terminal_id, receipt_no, transaction_date)`.

## Ingest outcome (in-memory result of `TransactionIngestService::ingest`)

| status | message | When |
| --- | --- | --- |
| `accepted` | `created` | New row inserted (unchanged) |
| `already_processed` | `already_processed` | Duplicate `transaction_id`, same tenant (unchanged shape) |
| `duplicate` | `duplicate_receipt_conflict` | Receipt rule hit (pre-check or index) (unchanged shape) |
| `failed` | `cross_tenant_transaction_id_collision` | Duplicate `transaction_id`, other tenant (new) |
| `failed` | `unique_constraint_violation` | Any other unique index (new; `details` = index name) |
| `failed` | `invalid_payload` | Missing required field incl. `net_sales` (US6 adds net_sales) |
| `failed` | `ingest_failed` | Other exceptions (unchanged) |

## Configuration (additive, `config/ingestion.php`)

| Key | Env | Default |
| --- | --- | --- |
| `timestamps.mode` | `INGESTION_TS_MODE` | `observe` (enforce = 422) |
| `timestamps.earliest` | `INGESTION_TS_EARLIEST` | `2020-01-01` |
| `timestamps.future_tolerance_minutes` | `INGESTION_TS_FUTURE_TOLERANCE_MINUTES` | `1440` |
| `timestamps.late_threshold_minutes` | `INGESTION_TS_LATE_THRESHOLD_MINUTES` | `1440` |
| `rejection_alerts.enabled` | `INGESTION_REJECTION_ALERTS_ENABLED` | `true` |
| `rejection_alerts.window_minutes` | `INGESTION_REJECTION_ALERT_WINDOW_MINUTES` | `60` |
| `rejection_alerts.threshold` | `INGESTION_REJECTION_ALERT_THRESHOLD` | `100` |
| `rejection_alerts.no_accept_hours` | `INGESTION_REJECTION_ALERT_NO_ACCEPT_HOURS` | `24` |
| `rejection_alerts.cooldown_minutes` | `INGESTION_REJECTION_ALERT_COOLDOWN_MINUTES` | `360` |

## Metrics (Redis via `App\Support\Metrics`)

New counters: `intake.rejected.code.{code}`, `intake.rejected.tenant.{tenant_id}`, `intake.rejected.terminal.{terminal_id}.{code}`, `intake.late_count`, `ingest.data_failure_count`, `ingest.cross_tenant_collision_count`. New bucket: `intake.arrival_lag_seconds`.
