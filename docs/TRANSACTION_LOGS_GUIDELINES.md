## Transaction Logs Module — Implementation Guidelines

Purpose
-------
Provide a clear, actionable implementation guide for the Transaction Logs module. The module must reliably record ingest events, validation outcomes, duplicate detections, forwarding attempts, and operator-facing audit entries. It should be safe to run in production at high throughput, be non-destructive to historical data, and enable downstream reconciliation (Web UI vs POS counts) and forensics.

Design Contract (short)
-----------------------
- Inputs: transaction payloads arriving via API/queues (one logical submission per `submission_uuid`), job processing lifecycle events, and forwarding attempt results.
- Outputs: persistent log entries (table(s)) that represent submission-level events, transaction-level statuses, and forwarding records. Read APIs for operator UI and admin tooling. Exportable for forensic analysis.
- Error modes: tolerate duplicate submissions (mark as DUPLICATE), validation failures (mark as ERROR), transient downstream failures (record retry attempts), and provide human-readable reason_details for each event.

Core Concepts
-------------
- Submission vs Transaction: a single client submission (identified by `submission_uuid`) can contain 1..N transaction records. We must record both submission-level events and per-transaction statuses.
- Non-destructive state: do not delete original rows. Mark rows with `validation_status` and `job_status` enums (e.g., RECEIVED, PROCESSING, COMPLETED, DUPLICATE, FAILED, ERROR) and persist human-readable `reason_code` + `reason_details` (JSON).
- Canonical identity: compute a canonical fingerprint for a transaction (receipt_no, tenant_id, terminal_id, amount, timestamp canonicalization etc.). Use it to claim identity in `transaction_identities` table for DB-level uniqueness.

Data Model (recommended)
------------------------
- `submission_events` (existing model)
  - id (pk), submission_uuid, event_type (RECEIVED, REJECTED, DUPLICATE_DETECTED, COMPLETED, FORWARD_ATTEMPT), reason_code, reason_details (json), created_at
  - Index: submission_uuid

- `transactions` (existing)
  - id, submission_uuid, tenant_id, terminal_id, receipt_no, canonical_fingerprint, validation_status, job_status, checksum, payload (json/text), created_at, updated_at

- `transaction_identities` (existing)
  - canonical_fingerprint (unique), transaction_id

- `webapp_transaction_forwards`
  - id, tenant_id, terminal_id, batch_id, batch_checksum, status, response_body, attempted_at, created_at

Insertion Points (where to log)
-------------------------------
1. On API acceptance (first layer): create a `submission_events` row with event_type=RECEIVED and include raw `submission_uuid` and minimal metadata (source IP, headers optionally masked).
2. After initial validation fails: create `submission_events` with event_type=REJECTED, reason_code=VALIDATION_ERROR and `reason_details` containing validation messages and a snapshot of the payload (or path to stored offloaded payload file).
3. When a duplicate is detected (application-level early submission_uuid or canonical identity claim fails): create event_type=DUPLICATE_DETECTED with `reason_code` like DUPLICATE_SUBMISSION or DUPLICATE_IDENTITY and reason_details referencing the existing transaction id or fingerprint.
4. On final successful processing: event_type=COMPLETED.
5. On forwarding attempts: event_type=FORWARD_ATTEMPT; reason_details contains HTTP status, response body, classification (LOCAL_VALIDATION_FAILED, DOWNSTREAM_ERROR), and remediation suggestion.

Implementation Details — code guidance
-------------------------------------
- Use the existing `SubmissionEvent` Eloquent model for all submission-level inserts. Keep `reason_details` as JSON-castable to allow structured machine parsing.
- Where to add calls:
  - `app/Services/JobProcessingService.php` — at the top of processing (RECEIVED), in validation branches (REJECTED), in the duplicate checks (DUPLICATE_DETECTED), and on completion (COMPLETED).
  - `app/Services/WebAppForwardingService.php` — create FORWARD_ATTEMPT events for both successful and failed forwards. Include the envelope checksum and batch_id in reason_details.

- Keep event creation lightweight and non-blocking. Persisting an event should not make the main pipeline brittle. Use DB transactions where needed to keep transactional semantics (for example: claim identity + write event in same DB transaction), but avoid long-running locks.

Reason Codes and Standardized Payload
------------------------------------
Provide an enumerated list of reason_code values and shapes for `reason_details` to keep UIs simple:
- VALIDATION_ERROR: { field_errors: {field: [errors]}, payload_snapshot: "s3://..." }
- DUPLICATE_SUBMISSION: { existing_transaction_id: 123, submission_uuid: "..." }
- DUPLICATE_IDENTITY: { canonical_fingerprint: "...", existing_transaction_id: 123 }
- FORWARD_SUCCESS: { batch_id: "..", batch_checksum: "..", forwarded_count: N }
- FORWARD_FAILURE: { batch_id: "..", batch_checksum: "..", classification: "LOCAL_VALIDATION_FAILED|DOWNSTREAM_ERROR|TIMEOUT", http_status: 422, response: "..." }

APIs / Read Models
------------------
- Read endpoints for operators should support:
  - Query by submission_uuid (returns submission_events + related transactions)
  - Query by transaction id (returns transaction + submission_events + forward records)
  - Aggregate counts: unique receipts per tenant/terminal over time window.

Testing Guidance
-----------------
- Unit tests:
  - Successful path: create submission -> JobProcessingService processes -> check transaction status COMPLETED and SubmissionEvent RECEIVED + COMPLETED.
  - Duplicate submission_uuid early: send second submission with same submission_uuid -> expect DUPLICATE tag on transaction and SubmissionEvent DUPLICATE_SUBMISSION.
  - Duplicate identity claim: two separate submissions with same canonical fingerprint -> second is marked DUPLICATE_IDENTITY and SubmissionEvent created.
  - Local forwarding validation: construct a sample envelope with boundary timestamp formats and assert the forwarder accepts correct timestamps and rejects wrong ones. Add tests around `isoTimestamp` helper.

- Integration tests (phpunit feature): run a small queue worker that processes a sample payload and asserts forwarding events written.

Migration & Backfill Notes (operator-run)
----------------------------------------
- The migration `database/migrations/2025_11_11_000000_add_unique_submission_uuid_to_transactions.php` currently skips index creation if duplicate `submission_uuid` groups exist. That's correct for safety.
- Before enabling a unique index in production:
  1. Run the duplicate inspector query (provided in the migration) on staging and prod
  2. For each duplicate `submission_uuid` group, choose a non-destructive remediation pattern. Example SQL to mark duplicates as DUPLICATE and nullify `submission_uuid` for subsequent rows:

```sql
-- non-destructive de-duplication pattern (example)
WITH ranked AS (
  SELECT id, submission_uuid,
         ROW_NUMBER() OVER (PARTITION BY submission_uuid ORDER BY id) as rn
  FROM transactions
  WHERE submission_uuid IS NOT NULL AND submission_uuid <> ''
)
UPDATE transactions t
SET validation_status = 'DUPLICATE', job_status = 'DUPLICATE', submission_uuid = NULL
FROM ranked r
WHERE t.id = r.id AND r.rn > 1;
```

This leaves one canonical row with the `submission_uuid` and marks others as duplicates. Test this on staging first.

Operational Runbook
-------------------
- If forwarding failures spike with classification LOCAL_VALIDATION_FAILED, check the envelope validator. Common cause: timestamp format mismatch; ensure `isoTimestamp` helper is used for all emitted timestamps.
- If duplicates appear unexpectedly, surface them to ops via an alert when duplicate groups exceed a threshold within a time window for a tenant.
- Keep `submission_events` retention policy aligned with audit needs (e.g., 365 days). Consider offloading payload snapshots to object storage and storing references in `reason_details`.

Security & Privacy
------------------
- Avoid storing raw sensitive data in plain text (card numbers, PANs). If a payload must be stored for forensics, strip or tokenise sensitive fields and store the rest, or store encrypted blobs with a key management policy.
- Mask PII in `reason_details` where operator UIs can display the values.

Performance & Scaling
---------------------
- Keep `submission_events` inserts small. If event traffic becomes very high, consider batching writes to a write-optimized table or partitioning by time.
- Indexes: keep the essential indexes to support queries by `submission_uuid` and `transaction_id`. Avoid wide JSON indexing in MySQL; prefer storing searchable fields as columns.

Acceptance Criteria
-------------------
1. SubmissionEvent rows are written for RECEIVED, REJECTED, DUPLICATE_DETECTED, COMPLETED, FORWARD_ATTEMPT as applicable.
2. Duplicate submissions are marked non-destructively and a SubmissionEvent exists describing the duplication.
3. Outbound forwarder emits timestamps matching contract (example: `YYYY-MM-DDTHH:MM:SS.sssZ`) and passes local validation tests.
4. Tests cover the happy path + 2 duplicate scenarios + forwarding timestamp format.

Appendix — Quick checks
-----------------------
- Query duplicates count (same as migration):

```sql
SELECT submission_uuid, COUNT(*) c
FROM transactions
WHERE submission_uuid IS NOT NULL AND submission_uuid <> ''
GROUP BY submission_uuid
HAVING c > 1
ORDER BY c DESC
LIMIT 100;
```

- Inspect recent forward failures (example): search logs for "Outbound payload validation failed" and look into reason_details for the envelope and transaction timestamp formats.

Questions to answer before coding
---------------------------------
1. Should full raw payloads be stored inline or offloaded to object storage? (recommended: offload)
2. What retention policy and access control do we want on `submission_events`?
3. Are there tenant-level rate limits or alerts to create if duplicate groups spike?

If you'd like, I can also:
- Draft the API endpoints and example JSON responses for operator UI.
- Create phpunit tests scaffolding for the described scenarios.
- Produce a small SQL script and a one-click artisan command to perform the non-destructive dedupe on staging.

---
Document created on: 2025-11-11
