# Transaction Ingestion Logging & Drop-Safety Plan

## 1. Objectives

- Ensure **every received transaction submission** (official + legacy/batch) is:
  - Either **persisted and queued** for processing, or
  - Explicitly **recorded as a failure** with enough context to trace and remediate.
- Eliminate any realistic path where a transaction can be **"accepted" by the API but invisible** in:
  - `system_logs`
  - `submission_events` / `submission_event_items`
  - Laravel application logs
- Improve operator visibility so POS teams can reconcile:
  - POS-side transmission logs
  - TSMS ingestion logs (per submission + per transaction).

## 2. Current State (Summary)

**Endpoints & flow**
- `POST /v1/transactions/official` → `TransactionController@storeOfficial`
  - Per-item success:
    - `transactions` row created
    - `SystemLog` (`OFFICIAL_TRANSACTION_INGESTION`) created
    - `AuditLog` (`OFFICIAL_TRANSACTION_RECEIVED`) created
  - Per-item failure:
    - `Log::error('Failed to process official transaction', ...)`
    - `SubmissionEventItem` with `status = FAILED` and reason
    - **No `SystemLog` row written for the failed item**
- Batch/non-official endpoint (`batchStore`):
  - Per-item failure logged via `Log::error('Failed to process transaction in batch', ...)`
  - Response JSON includes `failedTransactions`
  - No structured `SystemLog` rows for failures
- Middleware `ValidateTransaction` can reject completely invalid JSON **before** reaching controllers; it returns HTTP 400 but does not currently write `SystemLog` or structured submission events.

**Queue processing**
- `ProcessTransactionJob`:
  - On success: updates `transactions`, `jobs`, `validations`, logs `Transaction processed successfully` (info).
  - On validation failure: updates DB rows, logs warnings/errors; no additional `SystemLog` entries.
  - On missing transaction: logs error and returns.

**Drop windows** (no SystemLog, though failures are logged elsewhere):
- Per-transaction errors inside `storeOfficial` / `batchStore` (e.g., DB constraint, malformed payload) → captured as Laravel logs + `SubmissionEventItem`, but no `SystemLog` row.
- Upstream JSON/transport errors caught by `ValidateTransaction` → only HTTP 400 response.

## 3. Design Principles

1. **No silent drops:** every transaction payload that passes transport and hits our app should leave a durable trace.
2. **Dual-channel logging:**
  - Application logs (for operators/devs).
  - Structured DB logs (`SystemLog`, `SubmissionEvent`, `SubmissionEventItem`) for dashboards/reporting.
3. **Consistent log taxonomy:** align new `log_type` values with existing conventions in SystemLog/Audit docs and prefer any existing helpers/enums over raw `SystemLog::create([...])`.
4. **Standardized context schema:** for ingestion-related `SystemLog` rows, always include a common minimal context set (e.g., `submission_uuid`, `tenant_id`, `terminal_id`, `transaction_id`, `endpoint`, `error_code`, `payload_checksum`).
5. **Privacy- and size-aware:** never store full raw payloads in `context`; store checksums, body length, and at most a small truncated prefix.
6. **Low-risk, additive changes:** do not alter business logic or validation outcomes, only **add** logging/observability.
7. **Performance-aware:** avoid excessive logging volume (cap payload sizes, truncate JSON fields, and ensure indexes/retention for high-volume log types).

## 4. Target State

For each official submission (`submission_uuid`) and each transaction inside it:

- **Success path:**
  - `SystemLog` row (type `transaction`, log_type `OFFICIAL_TRANSACTION_INGESTION`, severity `info`).
  - Context follows the standardized schema (IDs, endpoint, checksum, no full payload).
  - Existing `AuditLog` + `SubmissionEvent` semantics preserved.
- **Failure path (per item):**
  - `SystemLog` row (type `transaction`, log_type `OFFICIAL_TRANSACTION_INGESTION_FAILED`, severity `error` or `warning`).
  - Context follows the standardized schema, plus a concise error descriptor.
  - Existing `SubmissionEventItem` (`status = FAILED`) remains the system of record.
- **Transport/JSON failure (middleware):**
  - `SystemLog` row (type `transaction`, log_type `TRANSACTION_PAYLOAD_INVALID`, severity `error`).
  - Context records route, client IP, body length, checksum, and a truncated prefix only.
  - HTTP 400 returned to client.

For batch/non-official endpoints:

- Per-item failures will emit a `SystemLog` with `log_type = BATCH_TRANSACTION_INGESTION_FAILED` keyed by `batch_id` + `transaction_id`, using the same standardized context schema.

## 5. Implementation Steps

### 5.1 Official Ingestion: Failure SystemLog

**Files:**
- [app/Http/Controllers/API/V1/TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php)

**Changes:**
1. In the per-transaction `catch (\Exception $e)` block inside `storeOfficial`:
   - After existing `Log::error('Failed to process official transaction', ...)` call:
     - Add `SystemLog::create([...])` (or the existing SystemLog helper) with fields:
       - `type = 'transaction'`
       - `log_type = 'OFFICIAL_TRANSACTION_INGESTION_FAILED'`
       - `severity = 'error'`
       - `terminal_uid = $terminal->serial_number ?? $request->terminal_id`
       - `transaction_id = $transactionData['transaction_id'] ?? 'unknown'`
       - `message = 'Official format transaction failed during ingestion'`
       - `context = json_encode([...])` following the standardized schema (IDs, endpoint, error code, payload checksum; no full payload).
2. Ensure we **do not rethrow** inside the per-item catch, so one bad transaction does not abort the whole submission (current behavior already respects this; keep it).

**Acceptance criteria:**
- For a failing official transaction (duplicate key, validation edge case, etc.), we see:
  - A `SystemLog` entry with `log_type = OFFICIAL_TRANSACTION_INGESTION_FAILED`.
  - A matching `SubmissionEventItem` with `status = FAILED`.
  - The submission as a whole still writes its top-level `SubmissionEvent`.

### 5.2 Batch Endpoint: Failure SystemLog

**Files:**
- [app/Http/Controllers/API/V1/TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php)

**Changes:**
1. Inside `batchStore`, in the inner `catch (\Exception $e)` for each transaction:
   - After `Log::error('Failed to process transaction in batch', ...)` add:
    - `SystemLog::create([...])` (or the helper) with fields:
       - `type = 'transaction'`
       - `log_type = 'BATCH_TRANSACTION_INGESTION_FAILED'`
       - `severity = 'error'`
       - `terminal_uid` (if resolvable from data or terminal lookup).
       - `transaction_id`.
      - `message = 'Batch transaction failed during ingestion'`.
      - `context` following the standardized schema, additionally including `batch_id`.

**Acceptance criteria:**
- Induce a single-record failure in a large batch (e.g., violate unique constraint).
- Response JSON shows one `failed` record.
- System Logs show a matching `BATCH_TRANSACTION_INGESTION_FAILED` row for that transaction.

### 5.3 Middleware: Invalid Payload Logging

**Files:**
- [app/Http/Middleware/ValidateTransaction.php](app/Http/Middleware/ValidateTransaction.php)

**Changes:**
1. When `$payload` is falsy/invalid:
   - Before returning HTTP 400, call `SystemLog::create([...])` with:
     - `type = 'transaction'`
     - `log_type = 'TRANSACTION_PAYLOAD_INVALID'`
     - `severity = 'error'`
     - `terminal_uid = null` (unknown at this point)
     - `transaction_id = null`
    - `message = 'Invalid JSON payload received on transaction endpoint'`
    - `context` including `path`, `client_ip`, body length, checksum, and a small truncated prefix of the body (no full payload).
2. In the `catch (\Exception $e)` block, log via `Log::error` and emit a `SystemLog` with `log_type = 'TRANSACTION_MIDDLEWARE_EXCEPTION'`.

**Acceptance criteria:**
- POSTing invalid JSON to the official ingestion route produces:
  - HTTP 400.
  - A `SystemLog` row with `TRANSACTION_PAYLOAD_INVALID`.

### 5.4 Optional: Queue-Level Failure SystemLog

**Files:**
- [app/Jobs/ProcessTransactionJob.php](app/Jobs/ProcessTransactionJob.php)

**Changes (optional, lower priority):**
1. Inside `handleError(\Throwable $e)` (or in the `catch` in `handle` if that is where final handling occurs):
   - Add `SystemLog::create([...])` for terminal job failures where transaction row exists but processing failed with an unhandled exception.
   - `log_type = 'TRANSACTION_PROCESSING_JOB_FAILED'`.

**Acceptance criteria:**
- For an induced unexpected exception in `TransactionValidationService`, we see a SystemLog entry tied to the `transaction_id`.

## 6. Testing Plan

1. **Unit / Feature tests**
   - Add or extend Feature tests in `tests/Feature/OfficialIdempotentReplayTest.php` or a new `OfficialIngestionLoggingTest` to cover each failure class:
     - **Official success:** trigger a successful official submission and assert a `SystemLog` row with `OFFICIAL_TRANSACTION_INGESTION` and the expected IDs.
     - **Official per-item failure:** induce a validation/DB error on a single item and assert a `SystemLog` row with `OFFICIAL_TRANSACTION_INGESTION_FAILED` plus a matching `SubmissionEventItem(FAILED)`.
     - **Batch per-item failure:** induce a single-record failure in a batch and assert a `BATCH_TRANSACTION_INGESTION_FAILED` `SystemLog` row keyed by `batch_id` + `transaction_id`.
     - **Middleware invalid JSON:** send malformed JSON, expect HTTP 400 and a `TRANSACTION_PAYLOAD_INVALID` row with route, IP, and non-empty checksum.

2. **Manual staging validation**
   - Use Postman collection to:
     - Send valid and invalid official submissions.
     - Verify:
       - `system_logs` table contents.
       - `submission_events` and `submission_event_items` rows.
       - `laravel-YYYY-MM-DD.log` entries.

3. **Monitoring hooks**
  - Optionally create a Horizon/TSMS dashboard widget showing counts by `log_type` for the above new values, to quickly spot ingestion issues.
  - Integrate these counts with existing circuit-breaker metrics (per tenant/terminal) so repeated ingestion failures can be surfaced to POS dashboards and operators.

## 7. Rollout & Risk

- Changes are **additive logging only**; they do not alter validation, DB schema, or response codes.
- Primary risk is **log volume growth**:
  - Mitigate by truncating stored `context` payloads and avoiding full payloads.
  - Ensure appropriate indexes exist for common ingestion queries (e.g., `(tenant_id, log_type, created_at)` or equivalent).
  - Monitor `system_logs` table size and ensure retention/archival policies are aligned with the increased volume.

## 8. Summary

Once implemented, any received transaction will be either:
- Successfully persisted and marked in `SystemLog` as ingested, or
- Explicitly flagged as a failed ingestion in both `SystemLog` and `SubmissionEventItem`, with enough context to trace the root cause.

This closes the observability gap where transactions could be rejected or fail early without showing up in the System Logs dashboard.
