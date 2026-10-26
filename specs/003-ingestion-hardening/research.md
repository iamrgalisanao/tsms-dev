# Research: Ingestion Hardening

## R1. Retirement response for the batch endpoint

- **Decision**: Keep `POST /api/v1/transactions/batch` registered inside the existing `auth:sanctum` + `abilities:transaction:create` group, pointed at a new `retiredBatch()` handler that returns HTTP 410 JSON `{success:false, error_code:"ENDPOINT_RETIRED", message, replacement:"/api/v1/transactions/official"}`. Drop the ingestion middleware (payload size, backpressure, circuit breaker, fairness) from this route. The flag-gated legacy route points to the same handler. Delete `batchStore()`'s body and the batch-only config guard usage.
- **Rationale**: Removing the route entirely would fall through to `web.php`'s HTML `Route::fallback`, which the Aug 2026 incident showed is harmful (HTML 200 instead of an API error). 410 tells a client the resource is permanently gone. Keeping auth means unauthenticated probes still get 401 and no information.
- **Alternatives**: 404 (ambiguous; looks like a deploy error); leaving the method but disabling with a flag (keeps the bypass one env change away).

## R2. Replacing `insertOrIgnore`

- **Decision**: Use a plain `DB::table('transactions')->insert()` inside the existing `DB::transaction` + `DeadlockRetryService` wrapper. Catch `QueryException` with SQLSTATE `23000` and driver code 1062, parse the index name from the message (`for key 'transactions.<index>'` or `for key '<index>'`), and classify:
  - `ux_transactions_transaction_id`: look up by `transaction_id` alone. Same tenant → `already_processed` (existing shape). Different tenant → `status:'failed'`, `message:'cross_tenant_transaction_id_collision'`, warning log with both tenant IDs; never return the other tenant's row id.
  - `ux_tx_tenant_terminal_receipt_date_unique`: look up by tenant + terminal + receipt + transaction_date → `duplicate` with `duplicate_receipt_conflict` (existing shape).
  - Any other unique index → `failed`, `message:'unique_constraint_violation'`, `details:` index name.
  Other SQLSTATE 22/23 errors (not-null, truncation) now surface as exceptions and reach the existing catch block, which already classifies them as data-level (`isInfrastructureFailure` false) and returns `ingest_failed`.
- **Rationale**: MySQL `INSERT IGNORE` downgrades strict-mode errors to warnings, so required-field violations were silently dropped. The global unique index on `transaction_id` with a tenant-scoped lookup produced the 6,572 "insert ignored but no existing transaction found" rows on staging. Catching inside the transaction closure is safe because a failed single-row INSERT does not abort the MySQL transaction; but to avoid relying on that, the lookup runs after the closure returns a classification marker.
- **Index names verified on staging**: `ux_transactions_transaction_id (transaction_id)`, `ux_tx_tenant_terminal_receipt_date_unique (tenant_id, terminal_id, receipt_no, transaction_date)`, `ux_transactions_refund_reference`. `transaction_date` is a STORED GENERATED column from `COALESCE(transaction_timestamp, completed_at, created_at)`.
- **Test DB caveat**: tests must run on MySQL (the `tsms-test-mysql` container), not SQLite, because error codes and messages differ. A guard skips the index-parsing tests on non-MySQL drivers.
- **Alternatives**: `upsert` (would overwrite data, wrong); keep `insertOrIgnore` and add a global lookup (still hides strict-mode errors).

## R3. Circuit breaker in the job context

- **Finding**: `CircuitBreaker::recordSuccess(null)` is a no-op when the breaker is closed, and calls `reset()` (fully closes it) when open or half-open. `TransactionIngestService` runs inside a queue job that was never admitted through `isAvailable()`, so it has no half-open generation. Today, any background ingest success force-closes an open breaker, bypassing the middleware's half-open probing.
- **Decision**: Remove both `recordSuccess()` calls from `TransactionIngestService`. Keep `recordFailure()` for infrastructure failures (unchanged). Recovery is decided only by the HTTP middleware's generation-tracked probes. Add `Metrics::incr('ingest.data_failure_count')` for data-level failures and classified failures.
- **Rationale**: There is no generation to pass from a job; passing null is exactly the bug. Success while closed did nothing anyway, so removing it changes behavior only in the open/half-open case, which is the fix.
- **Alternatives**: Thread a generation through the job (the job runs later, after the generation may have changed; meaningless).

## R4. Timestamp sanity at intake

- **Decision**: In `TransactionIntakeService::validateNestedTransactionContent`, after the existing regex rule passes, parse with `ProviderTimestampNormalizer` for the authenticated terminal and reject with `INVALID_TRANSACTION_TIMESTAMP` when the date has a zero component, fails `checkdate`, or is before `ingestion.timestamps.earliest` (default `2020-01-01`). Reject with `FUTURE_TRANSACTION_TIMESTAMP` when later than `received_at + ingestion.timestamps.future_tolerance_minutes`. Accepted sales older than `ingestion.timestamps.late_threshold_minutes` (default 1440) get `Metrics::incr('intake.late_count')`, `Metrics::bucket('intake.arrival_lag_seconds', …)` and a `late:true, lag_seconds` field on the accept log line.
- **Default future tolerance**: 1440 minutes (24h), not 10 minutes as first drafted. POS clocks drift and `local_time_with_z` providers send local time with a `Z`. A tight window risks rejecting legitimate sales, which would be a regression worse than the defect. Spec Assumptions are updated accordingly; ops can tighten it via env.
- **Rejection response**: same 422 shape as other structural failures, with the specific `error_code`. Persisted via `persistRejection` with that code.
- **Alternatives**: Reject late sales (rejected: offline backlogs are legitimate); validate in the ingest job (too late: the intake is already accepted with 200).

## R5. Rejection metrics and alerting

- **Decision**:
  - `persistRejection` adds `Metrics::incr("intake.rejected.code.{code}")`, `"intake.rejected.tenant.{tid}"`, `"intake.rejected.terminal.{termId}.{code}"` beside the existing global counter.
  - New `App\Services\Monitoring\IntakeRejectionMonitor::check()` scheduled every 15 minutes (`withoutOverlapping()->onOneServer()`), query: rejected intakes in the last `window_minutes` (default 60) grouped by tenant, terminal, `last_error_code`, using `idx_intake_status_received`, with `MAX_EXECUTION_TIME` guard. Rows ≥ `threshold` (default 100) alert. Second check: terminals with ≥ 1 rejection in the last 24h and no `transactions` row created in the last 24h (`idx_tx_ingest_tenant_terminal_status_created`).
  - Cooldown per (terminal, reason) via `RateLimiter::attempt`, matching `NotificationService::checkTransactionFailureThresholds`. Default 360 minutes.
  - Delivery: new `IntakeRejectionAlert` notification through the existing `sendToAdmins` path (database + mail channels as the other alerts use) plus a `SystemLog` warning and `Log::warning`.
- **Rationale**: Querying `transaction_intake` (already the source of truth for rejections) avoids new storage and survives Redis metric resets. The existing tenant-inactivity monitor alerts when transactions stop, but not when a terminal is still submitting and every submission is rejected; the second check closes exactly that gap.
- **Alternatives**: Alert from Redis counters (no windowing in `Metrics`); alert inline on each rejection (alert storm risk).

## R6. Correlation ID to ingest

- **Decision**: `TransactionIngestService::ingest(array $payload, ?string $correlationId = null)`. `ProcessTransactionIntakeJob` passes its `$traceId`. All ingest log lines include `correlation_id`. No schema change (the transaction is already linked by `submission_uuid`).

## R7. Log hygiene

- **Decision**: Replace `'payload' => $payload` and the whole-`$parent` logs in `TransactionIngestService` with a `logRef()` array: tenant_id, terminal_id, transaction_id, receipt_no, submission_uuid, correlation_id. Use `LogContext::ingestion()` where the job already does. `batchStore`'s request-body log disappears with US1.

## R8. Existing tests that use the batch route

Fifteen test files call `/api/v1/transactions/batch`. Batch-specific ones (`BatchStoreTerminalBindingTest`, `BatchIngestionTenantTerminalValidationTest`, `IngestionBatchLimitTest`, `TransactionIngestionTest_new` batch cases, the batch cases in the TransactionPipeline tests) are replaced by `BatchRetirementTest`. Middleware tests that use batch only as a vehicle (backpressure, fairness, payload size, circuit breaker isolation, license enforcement, limit boundary, route characterization) are retargeted to `/official` with an equivalent payload so their middleware coverage stays. Each change is listed in tasks.md and compared against the baseline run.

## Architecture review amendments (ARCHITECTURE_APPROVED_WITH_CHANGES, 2026-10-26)

These supersede the conflicting parts of R2–R5 above.

- **A1 (supersedes R2's index-name mapping).** Unique indexes differ by environment. Staging: `ux_transactions_transaction_id(transaction_id)` (not created by any migration), `ux_tx_tenant_terminal_receipt_date_unique`, `ux_transactions_refund_reference`; `submission_uuid` non-unique. Migration-built test DB: `trx_terminal_transaction_unique(terminal_id, transaction_id)`, `ux_transactions_submission_uuid` (unique), the receipt index and refund index; no global `transaction_id` unique. Production is unverified. Classification is therefore schema-independent: on any duplicate-key error (or a `0` return from `insertTransactionParent`), run ordered lookups **after the transaction closure returns**: (1a) same tenant + `transaction_id` → `already_processed`; (1b) only if none, `transaction_id` alone (id, tenant_id only) → `cross_tenant_transaction_id_collision` (same-tenant-first avoids mislabelling where the global unique is absent); (2) `findReceiptConflict` + `receiptConflictResult` (keeps the same-checksum → already_processed behavior); (3) otherwise `unique_constraint_violation`. The index name only goes into `details` and logs.
- **A2.** Lookups run outside the closure so they see rows committed by a concurrent transaction (REPEATABLE READ snapshot issue).
- **A3.** `insertTransactionParent(array $parent): int` keeps its signature (two tests subclass it). It calls `insert()` and returns 1. The 1062 is caught by its caller, detected via `$e->errorInfo[1] === 1062`, index parsed from `errorInfo[2]` only (never `getMessage()`, which carries SQL bindings).
- **A4 (moves into US2).** The outer catch must stop logging `$e->getMessage()`; log exception class, SQLSTATE and driver code. Needed in US2 because strict-mode errors will now throw.
- **A5.** Strict-mode errors previously coerced/truncated values instead of dropping rows. Staging check (last 60 days): max stored lengths customer_code 24, promo_status 16, receipt_no 19, hardware_id 33 against 191/128-char columns, so no truncation is occurring; the change is safe in practice.
- **A6 (US4).** The existing `tests/Unit/Services/TransactionIngestServiceCircuitBreakerTest.php::test_real_ingest_success_genuinely_invokes_record_success` asserts today's force-close and must be rewritten, not left failing.
- **A7 (supersedes R4 enforcement).** Staging check (last 60 days, 385,258 sales): 0 before 2020, 21 more than 24h after `created_at`, 90,208 (23%) older than 1 day. Timestamp checks ship behind `ingestion.timestamps.mode` = `observe` (default: log + `intake.timestamp_would_reject.{code}` metric, accept as today) | `enforce` (422). Validation checks the raw string's Y/m/d/H/i/s with `checkdate` **before** calling the normalizer (Carbon rolls `0000-00-00` and `02-30` over), runs only when the value is a string matching the format regex, and is wrapped in try/catch. If other structural errors exist, the existing STRUCTURAL_VALIDATION_FAILURE code wins; the timestamp code is used only when timestamp errors are the only errors.
- **A8 (US3 query).** The no-accepted-sales check uses one `transaction_intake` range scan on `idx_intake_status_received` / `received_at` for the last 24h grouped by terminal and intake/processing status, not the `transactions` index (its column order cannot range-scan `created_at`). Index presence is verified on staging before scheduling.
- **A8a (2026-10-26, amends A8).** A8's single 24h scan (all intake rows grouped by tenant/terminal with conditional sums) failed on staging at 17:09 after deploy with MySQL error 3024: it exceeded the 5 s `MAX_EXECUTION_TIME` cap (about 3.4M rows, 8 GB), so the check raised no alert although tenant 9 / terminal 46 had 14,592 rejected and 0 accepted intakes in 24h (also 35/21 and 35/24). It is replaced by two steps: Q1 groups only REJECTED rows in the window by tenant/terminal with `HAVING COUNT(*) >= no_accept_min_rejections` (range on `idx_intake_status_received`); Q2 checks only those candidates, 200 pairs per query, for any row with `intake_status IN (RECEIVED, ACCEPTED, QUEUED)` in the window (`idx_intake_tenant_terminal_status_received`; explicit `IN` rather than `<>`, guarded by a test that the list equals every `INTAKE_STATUS_*` constant except REJECTED). Staging measurements: Q1 1.1 s (range on `idx_intake_status_received`), Q2 38 ms. The uniform 5 s cap stays on every query. New key `ingestion.rejection_alerts.no_accept_min_rejections` (env `INGESTION_REJECTION_ALERT_NO_ACCEPT_MIN_REJECTIONS`, default 10, clamped to at least 1) keeps one-off rejections out of the candidate set. Follow-up: add a covering index `(intake_status, received_at, tenant_id, terminal_id)` if Q1 exceeds 2.5 s or any 3024 is logged for this check.
- **A9.** Concurrency and cross-tenant tests simulate the race through the `insertTransactionParent` seam with a realistic `errorInfo`, because a second real connection deadlocks against RefreshDatabase and the test schema lacks the global index.
- **A10.** `ReconcileStrandedIntake.php:276` also calls `ingest()`; it passes the intake's trace_id.
- **A11 (noted, out of scope).** BATCH_LIMIT_EXCEEDED and SUBMISSION_ALREADY_REJECTED do not call `persistRejection`, and processing FAILED_PERMANENT spikes are not alerted. Recorded as follow-ups.
- **A12.** The flag-gated legacy batch route is unauthenticated and overrides the authenticated one when enabled; its retirement response is the same 410 but without the 401/403 rows. The 410 still consumes the `pos-ingestion` throttle (acceptable).


Gate record: re-review returned ARCHITECTURE_APPROVED_WITH_CHANGES with two exact edits (same-tenant-first lookup; FR-019a wording + config:cache runbook step). Both applied 2026-10-26; no open architecture items. Status: ARCHITECTURE_APPROVED.

**A13 (correction to R1/A4/A5, 2026-10-26).** `config/database.php` sets `'strict' => false` for the mysql connection (staging identical), so sessions run with `sql_mode=NO_ENGINE_SUBSTITUTION`. Removing INSERT IGNORE therefore surfaces only duplicate keys (1062, classified per A1) and hard errors such as explicit NULL into NOT NULL (1048); over-length values are still truncated with a warning exactly as before, so no new rejection class is introduced on the success path (FR-022). A4 still applies because any QueryException message carries SQL bindings. The strict-mode test forces STRICT_ALL_TABLES for its own session only to prove IGNORE no longer masks errors; it does not reflect production behavior. Enabling strict mode is out of scope.
