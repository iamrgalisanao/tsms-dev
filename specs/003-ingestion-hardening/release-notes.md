# Release Notes: 003 Ingestion Hardening

Source findings: TSMS Defect Revalidation & Remediation Report (2026-10-26). Operator detail: `specs/003-ingestion-hardening/runbook.md`.

## Delivery status

US1–US7 complete and validated locally (each slice REVIEW_PASS and ARCHITECTURE_CONSISTENT; full suite after rebasing onto main with TSMS-004 merged: 591 passed / 103 failed, zero new failures against the 107-failure baseline). Committed locally on branch 003-ingestion-hardening (TSMS-000); not pushed or deployed. Terminal identity (TSMS-01/02), historical reclassification, rejected-intake replay and reconciliation (TSMS-09) are deferred.

## Changes

| Story | Finding | Change |
| --- | --- | --- |
| US1 | TSMS-05, new 1 | `POST /api/v1/transactions/batch` retired: 410 `ENDPOINT_RETIRED`; `batchStore()` removed. |
| US2 | TSMS-07, TSMS-04, new 4 | `INSERT IGNORE` replaced; duplicate keys classified (already processed, duplicate receipt, cross-tenant collision, unique violation). |
| US4 | TSMS-06 | Background ingest no longer force-closes an open circuit breaker; new `ingest.data_failure_count`. |
| US6 | TSMS-05 | Missing `net_sales` is rejected instead of stored as 0 (explicit 0 still valid). |
| US7 | TSMS-10 | Ingest logs carry references and correlation ID, not payloads or SQL bindings. |
| US5 | TSMS-08, new 3 | Timestamp checks (impossible / far-future dates), observe mode by default; late sales flagged. |
| US3 | TSMS-03 | Per-tenant/terminal/code rejection counters; `intake-rejection-monitor` alerts on spikes and "submitting but nothing accepted". |

### Fix: no-accepted-sales check timeout (2026-10-26)

On staging the no-accepted-sales check failed with MySQL 3024: its single 24h scan of all intake rows exceeded the 5 s cap, so no alert was raised for terminals with only rejections (for example tenant 9 / terminal 46, 14,592 rejected and 0 accepted). The check now uses two indexed reads (rejected candidates, then a chunked lookup of non-rejected intakes for those candidates only; research A8a). The 5 s cap is unchanged. New env key `INGESTION_REJECTION_ALERT_NO_ACCEPT_MIN_REJECTIONS` (default `10`, minimum 1): a terminal needs at least this many rejections in the window before "no accepted sales" can fire. Run `php artisan config:cache` after deploy.

### Fix: receipt-conflict lookups (2026-10-26)

The duplicate-receipt checks on ingest (`TransactionIngestService::findReceiptConflict`) and on replay (`TransactionIntakeService::replayReceiptConflicts`) wrapped the `receipt_no` column in `TRIM()` and, on ingest, had no `tenant_id` filter, so they could not use the receipt indexes: 17–32 s per lookup on staging. Both now filter on `tenant_id`, `terminal_id`, `receipt_no =`/`IN` and a `transaction_timestamp` range (±24h, upper bound +86401 s), with the existing ±24h PHP check unchanged: about 45 ms on staging. `receipt_no` is now trimmed on write (`original_payload` and `payload_checksum` stay raw).

## Behavior changes operators will see

- Any client still calling `/transactions/batch` gets 410 (staging shows no such traffic since June 2026).
- Intakes that used to end FAILED_PERMANENT "insert ignored but no existing transaction found" now end PROCESSED (already processed), DUPLICATE, or FAILED_PERMANENT with a specific code.
- In observe mode, a zero-date payload is still accepted at intake but now ends FAILED_PERMANENT/`ingest_failed` instead of being stored with coerced values.
- New admin notifications from `intake-rejection-monitor`.
- No HTTP response changes for valid `/official` submissions (baseline: main's 200 `PENDING`/`ACCEPTED` contract from TSMS-004, PR #18); no migrations.

## Pre-deploy checks

1. **Production unique indexes on `transactions`.** Migrations and staging differ: staging has a global unique on `transaction_id` (created outside migrations) and a non-unique `submission_uuid`; migrations create `(terminal_id, transaction_id)` unique and a unique `submission_uuid`. Confirm production. Without an index starting with `transaction_id`, the cross-tenant lookup scans the table (duplicate path only). If production has a unique `submission_uuid`, multi-transaction submissions record only the first item: later items now fail as `unique_constraint_violation` (before 003 they were silently ignored), and since TSMS-004 a replay shows them to the POS as 422 `PARTIAL_FAILURE` with resend guidance. That is a pre-existing defect, now visible to clients; raise it separately.
2. Confirm with POS providers that none still use `/transactions/batch`.
3. Pause the scheduler during the deploy's DDL window (no DDL in this feature, but other pending migrations).
4. After deploy: `php artisan config:cache`, PHP-FPM reload, `php artisan horizon:terminate`; confirm `intake-rejection-monitor` in `schedule:list`.
5. Keep `INGESTION_TS_MODE=observe`; review `intake.timestamp_would_reject.*` for at least a week before enforcing.

## Deferred and follow-ups

- Covering index `(intake_status, received_at, tenant_id, terminal_id)` on `transaction_intake` if the no-accepted-sales candidate query exceeds 2.5 s or any MySQL 3024 is logged for it.
- Move `intake-rejection-monitor` from a scheduler closure to its own artisan command with `runInBackground()`, so sequential closure tasks no longer delay it.
- Before a production deploy of the receipt-conflict fix: run a bounded leading-whitespace check on production `transactions.receipt_no` (staging: 0 rows) and confirm the column collation is PAD SPACE (staging `utf8mb4_unicode_ci`). Legacy rows with leading spaces or tabs no longer match the equality lookup.
- `transaction_submissions` lookups by `submission_uuid` take about 14 s on staging, because `submission_uuid` is only the second column of the unique `(terminal_id, submission_uuid)`. Add a standalone index or include `terminal_id` in those lookups.

- TSMS-01/02: French Baker terminal 46 identity and terminal change audit (business confirmation first).
- Reclassify the 6,572 historical "insert ignored" FAILED_PERMANENT rows (read-only report, then approved backfill).
- Approved replay command for rejected intakes; reconciliation report and SAP B1 (TSMS-09).
- Persist `BATCH_LIMIT_EXCEEDED` / `SUBMISSION_ALREADY_REJECTED` rejections; alert on processing FAILED_PERMANENT spikes.
- Circuit breaker `failure_count` never resets while closed (pre-existing).
- TTL or retention for per-terminal metric keys.
- Schema drift between migrations and staging/production `transactions` indexes.
- `tsms:reconcile` reads `submission_events`, which the live path never writes.
- Replay resend guidance: `cross_tenant_transaction_id_collision` (and possibly `unique_constraint_violation`) items are reported under `PARTIAL_BATCH_FAILURE` as resendable with a new `submission_uuid`, but the same `transaction_id` would collide again. Map them to a non-resendable item code (or "resend with a new transaction_id") and surface the specific code. Not a regression: before 003 these were the opaque "insert ignored" failure with the same hint.
- Replay of a FAILED_PERMANENT intake returns `error_code: cross_tenant_transaction_id_collision` to the POS, revealing that another tenant uses that transaction_id (also exposed before via `data.last_error_code`); consider a generic external code.
- `transactions.validation_status` enum lacks `FAILED`, which `ProcessTransactionJob` writes; non-strict MySQL stores it as blank.
