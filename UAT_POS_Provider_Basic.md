# POS Provider Basic UAT Suite

Minimal, high-impact acceptance tests for POS terminal integration. Designed for external POS providers to validate contract conformance quickly. Focus: ingestion, validation, auth, duplicates, void, batch, retry signal, and security rejection.

---
## 1. Scope
Covers only the critical path and high‑risk negative cases required for go/no‑go. Extended scenarios (performance, advanced retries, notifications) remain in `UAT_POS_Transaction_Controller.md`.

## 2. Legend
- EP = Endpoint
- UUID = RFC 4122 UUID (any version) enforced by `uuid` rule
- SIDEEFF = Side effects (DB row, queue job, log)

## 3. Environment & Preconditions
- Staging API base URL available
- Valid terminal auth token (Terminal T1) & tenant/customer mapping
- At least one processed transaction (for void test) or ability to process one prior to void case
- Ability to generate valid checksums (document algorithm separately)

## 4. Test Data Template
| Field | Example | Notes |
|-------|---------|-------|
| customer_code | CUST-1001 | Must exist in customers.code |
| terminal_id | 101 | Active POS terminal id |
| hardware_id | HW-AX9 | Arbitrary string |
| transaction_id | 4f3c6ca2-6c1e-4a3c-9e39-6f2b8c0c6d71 | Unique per submission |
| transaction_timestamp | 2025-08-13T10:15:22Z | ISO 8601 UTC |
| base_amount | 1250.50 | Numeric >= 0 |
| payload_checksum | 64 hex chars | SHA-256 (example) |
| void_reason | Customer canceled | For void test |

## 5. Test Case Matrix (Essential)
| ID | Title | EP / Method | Purpose | Input Variant | Expected (HTTP / Body) | SIDEEFF |
|----|-------|-------------|---------|---------------|-------------------------|---------|
| TC-01 | Single Transaction Happy Path | POST /api/v1/transactions/submit | Basic ingestion works | Valid payload | 200 `{ success:true, status:"queued" }` | Row inserted (PENDING), job queued |
| TC-02 | Invalid UUID Rejection | POST /api/v1/transactions/submit | Enforce UUID format | transaction_id malformed | 422 error transaction_id | None |
| TC-03 | Missing Field (base_amount) | POST /api/v1/transactions/submit | Required field validation | Omit base_amount | 422 error base_amount | None |
| TC-04 | Duplicate Same Terminal | POST /api/v1/transactions/submit | Prevent dup IDs | Resend TC-01 payload | 422 (or 200 duplicate if idempotent) | No new row/job |
| TC-05 | Batch Happy Path | POST /api/v1/transactions/official/batch | Multi-ingest | 3–5 valid UUIDs | 200 processed_count=N failed_count=0 | N rows + N jobs |
| TC-06 | Batch One Invalid UUID | POST /api/v1/transactions/official/batch | Reject invalid batch member | 1 bad UUID | 422 errors.transactions[i].transaction_id | None |
| TC-07 | Void Happy Path | PUT /api/v1/transactions/{uuid}/void | Authorized void | Matching body uuid + checksum | 200 success true void fields | Row updated (voided_at, reason), forward attempt |
| TC-08 | Void ID Mismatch | PUT /api/v1/transactions/{uuid}/void | Reject mismatch | Body transaction_id != route | 422 mismatch error | None |
| TC-09 | Unauthorized Submission | POST /api/v1/transactions/submit | Auth required | No / invalid token | 401 | None |
| TC-10 | Invalid Checksum | POST /api/v1/transactions/submit | Checksum enforcement | Corrupt checksum | 422 payload_checksum error | None |
| TC-11 | Cross-Terminal Void | PUT /api/v1/transactions/{uuid}/void | Enforce ownership | Token of different terminal | 404 (or 403) | None |
| TC-12 | Retry (Transient Failure Signal) | POST /api/v1/transactions/submit | Retry pipeline visible | Simulate downstream transient fail | 200 queued; later retry in Horizon | Job retried; retry log/record |
| TC-13 | Permanent Failure (No Retry) | POST /api/v1/transactions/submit | Distinguish non-retryable | Force validation fail in async | 200 queued; final status INVALID | Row INVALID; no retries |
| TC-14 | Rate Limit (If Enabled) | POST /api/v1/transactions/submit | Throttle behavior | Burst > limit | 429 | Some accepted; last rejected |
| TC-15 | Injection Attempt in transaction_id | POST /api/v1/transactions/submit | Security input filtering | SQL-ish string | 422 uuid error | None |

## 6. Detailed Steps (Representative Subset)
Below are explicit step sequences for ALL test cases. Where tooling differs (Postman, curl, automated runner), adapt but preserve assertions.

### TC-01 Single Transaction Happy Path
Steps:
1. Construct payload with unique UUID v4 and correct checksum.
2. POST to /api/v1/transactions/submit with valid Bearer token.
3. Assert HTTP 200.
4. Assert response JSON keys: success=true, status="queued", transaction_id matches request.
5. Query DB (transactions) for transaction_id; assert row exists & validation_status=PENDING.
6. Check queue (Horizon) for ProcessTransactionJob tagged with transaction_id.
7. (Optional) After processor runs, assert validation_status transitions to VALID.

### TC-02 Invalid UUID Rejection
1. Copy TC-01 payload; replace transaction_id with malformed string.
2. POST submit.
3. Assert 422; JSON errors.transaction_id exists.
4. Confirm no DB row inserted.
5. Confirm no job queued.

### TC-03 Missing Field (base_amount)
1. Copy valid payload; remove base_amount.
2. POST submit.
3. Assert 422; errors.base_amount present.
4. No DB row; no job.

### TC-04 Duplicate Same Terminal
1. Perform TC-01 (ensure success).
2. Immediately resend identical payload.
3. Observe response: record actual (expected 422 unique constraint or 200 duplicate flag).
4. Count rows in transactions table for transaction_id=UUID (must equal 1).
5. Ensure only one job instance (or subsequent attempt skipped).

### TC-05 Batch Happy Path
1. Build batch with 3–5 valid transactions (all unique UUIDs, valid checksums if per-item required).
2. POST to /api/v1/transactions/official/batch.
3. Assert 200; success=true; processed_count = N; failed_count=0.
4. Verify each UUID present in DB (status=PENDING initially).
5. Verify N jobs queued.

### TC-06 Batch One Invalid UUID
1. Start from TC-05 payload; change one transaction_id to malformed.
2. POST batch.
3. Assert 422; errors.transactions[index].transaction_id present.
4. Verify NONE of the batch UUIDs inserted (atomic rejection expectation).

### TC-07 Void Happy Path
Prereq: A VALID transaction owned by terminal (create via TC-01 then wait until processed).
Steps:
1. Poll transaction until validation_status=VALID (or wait known SLA time).
2. PUT /api/v1/transactions/{uuid}/void with body containing identical transaction_id, void_reason, checksum.
3. Assert 200; success=true; void_reason echoed; voided_at present.
4. DB: transaction voided_at not null, void_reason stored.
5. Confirm forward/notification job queued (if integration enabled).

### TC-08 Void ID Mismatch
1. Use same VALID transaction UUID in URL.
2. PUT request body with DIFFERENT valid UUID.
3. Assert 422 mismatch error; errors.transaction_id present.
4. DB: original transaction unchanged (voided_at still null if not voided previously).

### TC-09 Unauthorized Submission
1. Use TC-01 payload but omit Authorization header or use invalid token.
2. POST submit.
3. Assert 401 (Unauthenticated). Response contains message.
4. DB: no row created.

### TC-10 Invalid Checksum
1. Generate valid payload then alter 1 hex char in checksum.
2. POST submit.
3. Assert 422; errors.payload_checksum present (or generic checksum failure message).
4. No DB row; no job.

### TC-11 Cross-Terminal Void
Prereq: Transaction created & validated under Terminal A (token A).
Steps:
1. Acquire a different terminal token (Terminal B).
2. Attempt PUT /api/v1/transactions/{uuid}/void with matching body.
3. Assert 404 (or 403) per implementation.
4. DB: transaction still not voided.

### TC-12 Retry (Transient Failure)
1. Configure environment to force first processing attempt to throw a retryable exception (e.g., disable network dependency, then re-enable after initial fail).
2. Execute TC-01 submission.
3. In Horizon, observe job attempt #1 fails; attempt #2+ scheduled.
4. Restore dependency; wait for retry to succeed.
5. Assert final validation_status=VALID; retry history/log entry shows at least one retry.

### TC-13 Permanent Failure (No Retry)
1. Configure processing to trigger non-retryable validation failure (e.g., force invariant violation) without transient exception.
2. Submit valid-format transaction.
3. After job runs, assert validation_status=INVALID.
4. Confirm no further retries scheduled (attempt count=1).

### TC-14 Rate Limit (If Enabled)
1. Determine limit L (e.g., 60/min) from config.
2. Rapidly fire L valid submissions (can parallelize) and ensure most return 200.
3. Fire one additional request.
4. Assert 429; check rate-limit headers; Retry-After present.
5. DB: Total new rows = L (last one rejected).

### TC-15 Injection Attempt in transaction_id
1. Use payload where transaction_id = "4f3c6ca2-6c1e-4a3c-9e39-6f2b8c0c6d71' OR 1=1 --".
2. POST submit.
3. Assert 422; errors.transaction_id (UUID rule) blocks it.
4. No DB row, no job.

## 6.1 Optional Automation Hints
- Use a UUID generator function to prevent clashes.
- Abstract wait/poll logic for post-queue processing (poll every 2s up to timeout).
- Central helper to assert no DB row (negative tests) to reduce repetition.


## 7. Evidence Checklist Template
| Case | Request Logged | Response Logged | DB Verified | Queue Verified | Logs Captured | Result |
|------|----------------|-----------------|-------------|----------------|---------------|--------|
| TC-01 | Y | Y | Y | Y | Y | PASS/FAIL |
| TC-02 | Y | Y | Y(N/A row) | N/A | Y |  |
| ... |  |  |  |  |  |  |

## 8. Exit / Sign-Off Criteria (Minimal Suite)
All TC-01..TC-11 PASS (TC-12..TC-15 optional but recommended). No critical defects (P1) open. Duplicate handling behavior documented. Ownership & UUID rules enforced.

## 9. Open Questions / Decisions To Confirm
| Topic | Question | Action |
|-------|----------|--------|
| Duplicate response shape | 422 vs idempotent 200? | Decide & document |
| Cross-terminal duplicate policy | Allow same UUID across terminals? | Schema update if yes |
| Retry visibility to providers | Expose retry endpoint / webhook? | Product decision |
| Rate limiting scope | Per token or per tenant? | Confirm config |

## 10. Quick Curl Examples (Placeholders)
```bash
# Single Happy Path (replace TOKEN, IDs, CHECKSUM)
curl -X POST "$BASE/api/v1/transactions/submit" \
 -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
 -d '{"customer_code":"CUST-1001","terminal_id":101,"hardware_id":"HW-AX9","transaction_id":"4f3c6ca2-6c1e-4a3c-9e39-6f2b8c0c6d71","transaction_timestamp":"2025-08-13T10:15:22Z","base_amount":1250.50,"payload_checksum":"<64HEX>"}'

# Void
curl -X PUT "$BASE/api/v1/transactions/4f3c6ca2-6c1e-4a3c-9e39-6f2b8c0c6d71/void" \
 -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
 -d '{"transaction_id":"4f3c6ca2-6c1e-4a3c-9e39-6f2b8c0c6d71","void_reason":"Customer canceled","payload_checksum":"<64HEX>"}'
```

---
Prepared: 2025-08-13
