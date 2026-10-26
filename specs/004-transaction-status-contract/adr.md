# ADR-004: Transaction validation and processing status contract

- **Status:** Approved 2026-10-26 by the product owner (Rommel Galisanao), with the recommended answers to all questions in section 8 (see 8.1).
- **Date:** 2026-10-26
- **Findings addressed:** TSMS-11 (status not allowed by the enum), TSMS-12 (VALID with a FAILED job), TSMS-07 residue (the replay's blank-status workaround)
- **Inputs:** "TSMS Confirmed Defects & Remediation Brief" (2026-10-26), a code inventory of every status writer and reader on `main` 30741bde, and read-only staging queries (10.20.0.41, time-limited).

## 1. Context

### 1.1 Schema (staging, verified)

| Field | Type | Allowed values |
| --- | --- | --- |
| `transactions.validation_status` | ENUM, NOT NULL, default PENDING | PENDING, VALID, ERROR, INVALID |
| `transactions.job_status` | VARCHAR(20), default QUEUED | anything |
| `transaction_validations.status_code` | VARCHAR(20) | **FK** → `validation_statuses.code`: ERROR, INVALID, PENDING, REVIEW_REQUIRED, VALID |
| `transaction_jobs.job_status` | VARCHAR(20) | **FK** → `job_statuses.code`: COMPLETED, FAILED, PENDING, PERMANENTLY_FAILED, PROCESSING, QUEUED, RETRYING, RUNNING |

The app's MySQL connection is non-strict (`'strict' => false`). A value outside the enum is stored as `''` without any error.

### 1.2 What the code does today

- `Transaction::VALIDATION_STATUS_FAILED = 'FAILED'`. **FAILED is not in the enum and not in `validation_statuses`.**
- `ProcessTransactionJob`:
  - **Business validation failure** (lines 159/169) writes `transaction_validations.status_code='FAILED'`, which the FK rejects, so an exception is thrown. `handleError` then writes `'FAILED'` again (line 364) and throws again, before the transaction row is saved. **A genuine business-invalid sale can never be recorded as such.** It stays PENDING until the watchdog blanks it. Staging confirms this: the last 200,000 validation rows are all VALID.
  - **Infrastructure failures** (exception, timeout, MaxAttemptsExceeded through `failed()`) go through `handleError`. It sets `job_status=FAILED` and `validation_status='FAILED'` (stored as `''`) unless the status is already VALID. It also adds 1 to `job_attempts`, and the catch-then-`failed()` path adds 1 twice.
  - Queue settings: `$tries=3`, `$timeout=120`, `$backoff=10`, `ShouldBeUnique('txn:{id}')`, and `WithoutOverlapping('txn:{id}')->releaseAfter(10)->expireAfter(600)`. Every release counts as an attempt, so lock contention or timeouts can exhaust the 3 tries **without `handle()` ever validating anything**.
- **Watchdog** (`routes/console.php`, transactions-watchdog):
  - It marks PENDING rows older than the threshold as validation `'FAILED'` (→ `''`) and job FAILED, with the error "Watchdog timeout exceeded".
  - It adds 1 to `job_attempts` even when `ShouldBeUnique` silently drops the dispatch.
- `ERROR` is never written on a live path. `INVALID` is written only by a test command and by unrouted controller code.

### 1.3 Data on staging (2026-10-26)

| validation_status | job_status | Rows | last_error | Meaning |
| --- | --- | --- | --- | --- |
| `''` | FAILED | 28 | ProcessTransactionJob has been attempted too many times (`job_attempts=1`) | Retries exhausted by release or timeout; **never validated** |
| `''` | FAILED | 10 | Watchdog timeout exceeded | Stuck PENDING, then blanked; **never validated** (some may be blocked business failures, see 1.2) |
| VALID | FAILED | 16 | ProcessTransactionJob has been attempted too many times | **Validated successfully**, then a later retry ran out of attempts and `handleError` downgraded the job status. May 4 to June 4, 2026. |

The 38 blank rows run from April 22 to October 26, 2026. The newest is from 19:23 today, so they are still being produced.

### 1.4 Readers that misread blank or FAILED rows

| Reader | Problem |
| --- | --- |
| `TransactionController::status` | Reports `''` as PENDING |
| Frontend `TransactionTable.jsx` | Shows `''` as **"RECONCILED"** |
| `NotificationService` failure alerts | Only count `INVALID`, so job-written failures never alert |
| `TransactionCollection` | Only counts `INVALID` |
| `TransactionDetailService` | Only counts `ERROR` |
| Blade views | Key their badges and retry buttons on `ERROR` |
| Dashboard tax figures, exception counts, tenant audit | Use `IN (FAILED, INVALID, ERROR)`, which misses `''` |
| `scopeFailed`, prune | Use `='FAILED'`, which never matches |

Sales sums in `app/Services/Reports/*` and the dashboard gross sales **don't filter on validation status at all**. Invalid and unvalidated rows count toward sales.

## 2. Decision

**D1. Two separate fields with one meaning each.**
- `validation_status` is the **business outcome only**: `PENDING` (not decided yet), `VALID` or `INVALID`.
- `job_status` is the **processing lifecycle only**: `QUEUED`, `PROCESSING`, `COMPLETED` or `FAILED`.
- `ERROR` stays in the enum and lookup table for history and rollback, but is deprecated and never written. `FAILED` is never written to `validation_status`.

**D2. A business validation failure** sets `validation_status=INVALID`, `job_status=COMPLETED` (processing finished; the result is invalid) and `transaction_validations.status_code='INVALID'`, with the reasons stored. `INVALID` already exists in both the enum and the FK table, so **no schema migration is needed.**

**D3. An infrastructure failure** (exception, timeout, MaxAttemptsExceeded, watchdog) never changes `validation_status`. It stays `PENDING`. It sets `job_status=FAILED` and `last_error`, makes the row eligible for a controlled retry, and increments a metric that can alert.

**D4. Final states are permanent.**
- Once `validation_status` is `VALID` or `INVALID` and `job_status` is `COMPLETED`, no failure handler may change either field. A late `failed()` call after success only logs and records a metric.
- `job_attempts` counts real `handle()` runs only.

**D5. Constants:** add `VALIDATION_STATUS_INVALID='INVALID'`, remove `VALIDATION_STATUS_FAILED`, and fix every writer and reader in 1.4 to use D1. Dead writers (`JobProcessingService`, `TransactionService::updateStatus*`, `TransactionProcessingService`, the unrouted controller paths) are left untouched in this change and listed for a separate dead-code cleanup.

**D6. Retry policy for `ProcessTransactionJob`:** stop exhausting tries through lock releases. `ShouldBeUnique` already prevents duplicate dispatch, so either remove `WithoutOverlapping` or use `->dontRelease()`, and switch to `retryUntil()` with `maxExceptions`. The exact choice is made during implementation, backed by tests that simulate contention and a timeout.

**D7. Watchdog:**
- For a stale PENDING row: re-dispatch it, or after N real attempts set `job_status=FAILED` and alert. It never touches `validation_status`.
- It stops counting attempts for dispatches that `ShouldBeUnique` drops.

**D8. What the replay (POS) reports, per state:**

| State | Replay response |
| --- | --- |
| VALID + COMPLETED | COMPLETED (unchanged) |
| VALID + FAILED (legacy TSMS-12 rows) | COMPLETED. The sale is validated; reporting it as failed would cause resends and duplicates. |
| INVALID | FAILED: correct and resend with a new `submission_uuid` |
| PENDING (any job status, including FAILED) | PENDING / ACCEPTED: don't resend; TSMS retries validation |
| `''` (legacy) | PENDING, after data remediation turns these rows into PENDING + FAILED. The current workaround maps `''` to FAILED, and this ADR would remove it. |

**D10. Sales sums** (from Q4): reports and dashboard gross and net sales exclude `validation_status=INVALID` in addition to voided rows. `PENDING` stays included, because a recorded sale that hasn't been validated yet still counts.

**D9. Strict SQL mode** for the app connection is the end goal. It belongs in a separate change: first CI tests run in strict mode, then staging. That turns any future invalid write into an error instead of a blank.

## 3. Data remediation (separate approval; dry-run first, idempotent, audited)

1. **38 blank rows:** set `validation_status=PENDING` and `job_status=FAILED`, then re-validate them through a dedicated command. It runs in dry-run mode by default, processes one row at a time, logs before and after, and rows that end INVALID record their reasons.
2. **16 VALID+FAILED rows:** set `job_status=COMPLETED` and mark the latest `transaction_jobs` row COMPLETED, with an audit entry. `validation_status` is unchanged.
3. **Before/after reconciliation:** per tenant per day, count and sum `gross_sales` and `net_sales` by status. Changes must be explained row by row. Financial totals in TSMS don't change. Downstream (SAP B1) impact is confirmed separately.

## 4. Consequences

- **Positive:**
  - Invalid sales become visible and correctly labelled.
  - Infrastructure failures stop masquerading as business failures and get retried.
  - TSMS-12 can't recur.
  - No schema migration on large tables, so no metadata-lock risk.
- **Changes users will notice:**
  - Dashboard and alert counts change: `INVALID` now appears.
  - The frontend no longer shows blank rows as "RECONCILED".
  - POS replies for unvalidated sales change from FAILED to PENDING.
  - Invalid rows still count toward sales sums. **This is a business decision** (Q4).
- **Risks:**
  - Readers missed by the inventory. Mitigated by a DB-level check that no new `''` rows appear, and by tests per reader.
  - Retry-policy changes affect throughput on an already overloaded staging DB. Mitigated by metrics before and after.
- **Rollback:** code-only, with no schema change. Data remediation is done by a reversible, audited command that stores previous values.

## 5. Alternatives considered

- **Add `FAILED` to the enum and to `validation_statuses`.** Rejected. It keeps business and infrastructure failures mixed together, needs an `ALTER` on a 2.2M-row table (metadata-lock risk, as in the Aug 2026 incident), and still leaves TSMS-12 in place.
- **Make the replay read `job_status`** (the brief's suggestion for TSMS-12). Rejected. All 16 rows are validated sales, and reporting them FAILED would trigger resends and duplicates.
- **Do nothing and rely on the replay workaround.** Rejected. Reports, alerts and the UI stay wrong, and business-invalid sales still can't be recorded.

## 6. Implementation slices (each with review and an architecture check; ingestion work is high-risk)

1. Job failure semantics: D2, D3, D4, D5 (job and model), with tests for business failure, infrastructure failure, a late `failed()` call, and the FK-valid audit write.
2. Retry policy and watchdog: D6 and D7, with contention and timeout tests and before/after metrics on staging.
3. Readers: everything in 1.4, the frontend label, and the replay mapping (D8). Also D10: exclude `INVALID` from gross and net sales sums.
4. Data remediation command and reconciliation report (section 3), run only with approval.
5. Strict-mode tests in CI (D9), then a staging rollout plan.

## 7. Success criteria

- Zero new `validation_status=''` rows, and zero new VALID+FAILED rows, checked by a daily query with an alert.
- A business-invalid test transaction ends `INVALID` + `COMPLETED`, with an `INVALID` audit row, in a strict-mode test run.
- An infrastructure-failure test ends `PENDING` + `FAILED` and is retried; a late `failed()` call after success changes nothing.
- "attempted too many times" for `ProcessTransactionJob` falls to near zero on staging.
- Every reader in 1.4 has a test for `INVALID`, `PENDING`+`FAILED`, and legacy `''`.
- The 38 + 16 rows are remediated with a signed-off before/after reconciliation.

## 8. Questions for approval

1. **Business-invalid sales:** is `INVALID` + `COMPLETED` the right final state (D2)?
2. **POS reply for a recorded but not-yet-validated sale:** is PENDING ("don't resend") right (D8)?
3. **The 10 "Watchdog timeout" rows:** re-validate them like the other 28 (recommended), or review them by hand first?
4. **Sales sums:** should reports and the dashboard exclude `INVALID` (and `PENDING`?) transactions from gross and net sales? Today they include everything that isn't voided.
5. **Strict SQL mode:** approve working towards it (D9) as a follow-up?
6. **Dead status-writing code:** remove it in a separate cleanup (recommended), or as part of this work?

### 8.1 Answers (approved 2026-10-26)

1. Yes. A business-invalid sale ends `INVALID` + `COMPLETED` (D2).
2. Yes. A recorded but not-yet-validated sale gets PENDING / ACCEPTED, "don't resend" (D8).
3. Re-validate all 38, including the 10 watchdog rows, through the remediation command (section 3).
4. Exclude `INVALID` from gross and net sales sums; keep `PENDING` included (D10).
5. Yes. Work towards strict SQL mode as a follow-up (D9).
6. Remove dead status-writing code in a separate cleanup, not in this work.

## 9. Slice 2 design (architect-approved 2026-10-26)

**D6, retry policy.** `ProcessTransactionJob` keeps `ShouldBeUnique`, which holds its lock from dispatch to completion. Changes:
- Drop the `WithoutOverlapping` middleware. Its release loop is what produced the zero-run MaxAttempts failures.
- Set `uniqueFor = 1800`.
- Replace `$tries` with `retryUntil()` = now + 15 min, `maxExceptions = 3`, backoff `[10, 60, 180]`, `timeout = 120`, `failOnTimeout = true`.
- Final failure → `handleError` (slice 1): job FAILED, validation stays PENDING. Add metric `transaction_job.failed_infra`.

**D7, watchdog.** Moves into a testable `TransactionWatchdogCommand` with the same schedule name.
- Stale PENDING+QUEUED rows are re-dispatched only when the unique lock can be acquired; otherwise the row is counted as `skipped_inflight`.
- Re-dispatch goes through `IngestionQueueRouter` (crc32 shards). The old `tenantId % 8` routing was a bug.
- PENDING+FAILED rows are re-dispatched with exponential backoff, up to `max_requeue_attempts` (default 3). The counter lives in the cache, so no migration is needed. When the cap is reached: log, metric, and one admin alert per row.
- The max-pending branch sets only `job_status=FAILED` through the conditional atomic update. **It never writes `validation_status`.**
- `job_attempts` is never touched. Batches are 200 rows by id.

**Prune guard.** The prune never deletes `PENDING` rows, and by default deletes nothing: `TX_PRUNE_DELETE_ENABLED=false`. It keeps an extended inventory snapshot. Any hard-delete retention policy needs its own ADR.

**Defaults to confirm with the product owner before deploying:**
- No transaction hard deletes.
- Watchdog retry cap of 3, then an admin alert through the NotificationService admin recipients.
- `retryUntil` window of 15 minutes.

**Prune "38 counted, none deleted":** to be reproduced on the test database and the result recorded here.

### 9.1 Slice 2 implementation notes (revalidated 2026-10-26)

**Prune "counted but none deleted", reproduced on the test DB.** `'FAILED'` is not a `validation_status` enum value.
- A SELECT that uses an index ref on `idx_tx_logs_status_id` coerces the literal to enum index 0 and matches legacy `''` rows (4 counted).
- The DELETE used a range on `idx_tx_logs_created_id` with string comparison and matched 0.
- So the result depends on the query plan: H1 confirmed, H2 (cutoff or timezone) rejected. The prune no longer filters on `'FAILED'` and never deletes.

**Accepted refinements.**
- `handleError` returns `failed|terminal|missing|error`, and treats a 0-row update as a late failure only when the re-read row is terminal (MySQL reports changed rows, not matched rows).
- Branch 2 leaves `job_status=FAILED` while re-dispatching.
- Watchdog cap alerts are collected per run into one summary `TransactionRetryCapReachedAlert` (count plus up to 20 refs, error categories only), with a per-row cache flag lasting 7 days.
- Branch 2 scans through a rotating cache cursor, up to `max_failed_scan` (default 1000), so capped rows can't starve higher ids.
- The prune snapshot is one hinted `GROUP BY validation_status` plus a PENDING+job FAILED count. With `TX_PRUNE_DELETE_ENABLED=true` it only logs a warning.

**Review change to D6.** `retryUntil()` is replaced by `$tries = 5` (`TX_JOB_TRIES`). A dispatch-time deadline would fail jobs unrun during a processing backlog; with `WithoutOverlapping` removed, a try only counts real pickups and timeouts. Worst-case lifetime is 1030 s, under `uniqueFor` 1800 s.

**Defaults confirmed by the product owner (2026-10-26):**
- No hard deletes of transactions.
- Watchdog retry cap of 3, then one summary admin alert per run.
- `job_tries` of 5.

## 10. Slice 3 design (architect-approved 2026-10-26)

**Legacy `''` rows until slice 4** are treated as "processing failed / needs attention". They are never RECONCILED and never INVALID. Sales sums count them like PENDING (unvalidated recorded sales).

**Readers.**
- Every reader in 1.4 moves to D1.
- `VALIDATION_STATUS_FAILED` is removed. `scopeProcessingFailed` is added, and `scopeFailed` stays as a deprecated alias of `invalid()`.
- New `PROCESSING_FAILED` pseudo-status for the transaction-log filters and export, meaning `job_status=FAILED AND validation_status IN ('PENDING','')`.
- `NotificationService` gets a separate processing-failure alert type. `TenantIngestionAuditService` splits its count into `invalid_count` and `processing_failed_count`, and keeps `invalid_or_failed_count` as their sum.
- Blade views and the React views (`TransactionTable`, `TransactionDetailPanel`, `FilterBar`) show INVALID, "PROCESSING FAILED" and PENDING, never RECONCILED for `''`.

**D8 replay.** `''` still maps to FAILED until slice 4. The watchdog only retries PENDING, so telling a POS PENDING for a `''` row would leave it waiting forever. Slice 4 changes `''` to PENDING together with the remediation. ERROR also maps to FAILED.

**D10.**
- New shared helper `App\Support\Reporting\CountableSales`. `apply()` adds the existing config-gated void filter plus `validation_status <> 'INVALID'`. `sumExpr()` gives conditional sums where status counts must stay visible.
- Applied to: SalesReportDataService, HourlyReportService, CommercialReportsController, FinanceCalculationService, the TransactionLogController totals, DashboardService and DashboardController KPI/chart sums, and the summary builders RefreshDailyTransactionSummaries and ReportingRefreshCommand. ReportingRefreshCommand's `issues_*` columns are repurposed to INVALID.
- **No backfill:** staging has 0 INVALID rows (checked 2026-10-26).

**Deploy:** slices 1, 2 and 3 ship together.

**Recommended scope limits (to confirm with the product owner):**
1. Dashboard KPIs and `transactions_hourly`/`transactions_daily` don't exclude voids today. Add only the INVALID exclusion now; align voids in a separate ticket.
2. VALID-only sums (top tenants, revenue composition, commercial month/YTD) also exclude PENDING. Leave them and record the gap.
3. `Tenant::getDailySalesTotal`, used by the ingestion daily-limit check, stays unchanged.
4. Approve the `PROCESSING_FAILED` filter and the new processing-failure alert type.
