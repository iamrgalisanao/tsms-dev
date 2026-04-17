# TSMS Transaction Intake Refactor – Implementation Plan & Architecture Guide

## Status: Approved / Ready for Execution

> [!IMPORTANT]
> **Architectural Approval: Conditions Apply**
> This refactor is architecturally sound and aligns with industry standards for high-concurrency transaction ingestion. Implementation must follow the safeguards, boundaries, and validation gates defined below.

---

## 1. Objective
Refactor the TSMS transaction intake flow from a synchronous, DB-heavy request path into a **thin intake + asynchronous processing architecture**. 

**Primary Goals:**
- Handle higher transaction concurrency
- Support multiple tenants and deep terminal counts without worker exhaustion
- Ensure idempotency (submission & business level)
- Reduce timeout risks and eliminate deadlock retry loops in the HTTP path
- Enhance observability, replayability, and operational control
- **Preserve existing POS API contracts** (No change to POS firmware or software)

---

## 2. Architecture Decision Summary

### Current Problem
The existing flow performs too much work inside the HTTP request lifecycle:
- Database-heavy operations (multiple inserts/updates)
- Enrichment logic and downstream forwarding
- Deadlock retry loops that block Apache/PHP workers
- High risk of request timeouts and lock contention under burst load

### Target Pattern: Durable Intake-First
1. **Authenticate** request
2. **Gatekeep** (Minimal validation)
3. **Persist** raw intake record quickly
4. **Acknowledge** immediately (202 Accepted)
5. **Process** full business logic asynchronously via queue workers

---

## 3. Execution Boundaries

### In Scope
- Durable raw intake persistence layer
- Thin HTTP controller path
- Async business validation and heavy writes
- Robust idempotency at both submission and business-transaction levels
- Clear intake/processing state machine
- Observability and metrics instrumentation
- Staged rollout (Shadow mode, Tenant Pilot)

### Out of Scope
- Breaking changes to payload guidelines 2.1 / 2.2
- POS-side changes (firmware/software)
- Downstream business modules not directly affected by intake
- Broad UI redesign (outside monitoring needs)

---

## 4. Governance & Readiness Checklist

### Branching & Environment
- [ ] Dedicated feature branch: `feature/tsms-intake-async-refactor`
- [ ] No direct work on protected branches (`main`, `production`)
- [ ] Classification: **High Risk / Infrastructure-Critical Refactor**

### Technical Baseline
- [ ] Verify existing intake endpoint contract documentation
- [ ] Baseline performance metrics collected (latency, timeout rates)
- [ ] Queue infrastructure (Redis/RabbitMQ) health validated
- [ ] Tenant-terminal trust relationship logic documented

---

## 5. Target Design

### 5.1 Synchronous Path (HTTP Layer)
The request path must **only**:
1. Authenticate terminal/API client
2. Parse request safely
3. Validate "Layer A" (Envelope structure, checksum)
4. Persist raw intake record (including `REJECTED` states for failed Layer A validation).
5. Dispatch to queue for `ACCEPTED` records (Durable pattern).
6. Return acknowledgment.

> [!NOTE]
> **Auditability of Rejections**
> All Layer A validation failures (e.g., invalid JSON, checksum mismatch) are persisted to the `transaction_intake` table with `intake_status = REJECTED` to facilitate troubleshooting and abuse analysis. Fundamental authentication failures may be rejected before persistence.

#### Response Semantics
- **202 Accepted**: Raw intake durably persisted and accepted for asynchronous handling.
- **200 OK**: Permitted only for backward compatibility if specifically required by legacy endpoints.
- **4xx (e.g., 400, 401, 403)**: Envelope validation failure or authentication/authorization rejection.
- **429 Too Many Requests**: Rate limit exceeded (per terminal/tenant).
- **503 Service Unavailable**: Global overload or transient intake persistence failure (e.g., DB down).

**Duplicate Submission Behavior:**
If a `submission_uuid` already exists in the intake table, return `202 Accepted` immediately without re-enqueuing, as the existing record is already in the pipeline.

### 5.2 Asynchronous Path (Queue Worker)
The worker is responsible for:
1. Fetching intake records
2. "Layer B" Business Validation
3. Idempotency checks (Business-level)
4. Writing transaction and child records (Sales, Tax, etc.)
5. Enrichment and downstream integration
6. Retry policy enforcement and final state marking

#### Tenant Trust Policy
> [!IMPORTANT]
> **Authoritative Identity Rule**
> The `tenant_id` provided in the request payload must **not** be treated as authoritative unless it matches the authenticated terminal-to-tenant mapping verified during authentication.

---

## 6. Validation Boundary Design

| Layer | Type | Scope | Failure Response |
| :--- | :--- | :--- | :--- |
| `Layer A` | Sync | Auth, JSON structure, Envelope fields, Checksum, Size limits | Immediate Reject (4xx). Persisted with `REJECTED` status for auditability. |
| **Layer B** | Async | Field logic, Enums, Reference integrity, Tax consistency, Tenant mapping | Mark `FAILED_PERMANENT` / `FAILED_RETRYABLE` |
| **Layer C** | Async | Downstream contract compatibility, Endpoint readiness | Dead-letter or Retry logic |

---

## 7. Intake Table Design (`transaction_intake`)

| Column | Type | Description |
| :--- | :--- | :--- |
| `id` | BIGINT | Primary Key |
| `submission_uuid` | UUID | Unique (Submission-level idempotency) |
| `tenant_id` | INT | Partitioning / Isolation |
| `terminal_id` | INT | Source identification |
| `payload_checksum`| STRING | Data integrity check |
| `payload` | JSON | Raw submission content (MySQL JSON type) |
| `payload_size_bytes`| INT | Request size for abuse monitoring |
| `source_ip` | STRING | Client IP address |
| `intake_status` | ENUM | RECEIVED, REJECTED, ACCEPTED, QUEUED |
| `processing_status`| ENUM| PROCESSING, PROCESSED, DUPLICATE, FAILED_*, DEAD_LETTERED |
| `attempt_count` | INT | Retry tracker |
| `last_error_code` | STRING | Observable error code |
| `last_error_message`| TEXT | Error details for troubleshooting |
| `duplicate_of_intake_id`| BIGINT | Reference to original intake (Nullable) |
| `trace_id` | UUID | Correlation for observability |
| `received_at` | TIMESTAMP | Record creation time (Synchronous) |
| `queued_at` | TIMESTAMP | Time of successful queue dispatch |
| `processed_at` | TIMESTAMP | Final worker completion time |

**Required Local Indexes:**
- `submission_uuid` (Unique)
- `tenant_id`, `terminal_id`
- `received_at` (For intake latency analysis)
- `processing_status` (For queue lag monitoring)

---

## 8. State Transition Matrix

### Intake States
| From | To | Trigger |
| :--- | :--- | :--- |
| `RECEIVED` | `ACCEPTED` | Successfully persisted to DB |
| `RECEIVED` | `REJECTED` | Layer A validation failure |
| `ACCEPTED` | `QUEUED` | Dispatched to asynchronous queue |

### Processing States
| From | To | Trigger |
| :--- | :--- | :--- |
| `QUEUED` | `PROCESSING` | Worker picks up the job |
| `PROCESSING` | `PROCESSED` | Successful Layer B & Layer C validation and write |
| `PROCESSING` | `DUPLICATE` | Business-level idempotency match |
| `PROCESSING` | `FAILED_RETRYABLE` | Transient failure (DB lock, downstream down) |
| `FAILED_RETRYABLE`| `PROCESSING` | Retry attempt |
| `FAILED_RETRYABLE`| `DEAD_LETTERED` | Max retries exceeded |
| `PROCESSING` | `FAILED_PERMANENT` | Validation/Business logic error |

---

## 9. Idempotency Strategy

### 9.1 Submission-Level
Use `submission_uuid` to protect against network retries and duplicate packet delivery. Handle at the storage layer via unique constraint.

### 9.2 Business-Level
Define a canonical fingerprint to protect against the same business event arriving via a different submission packet:
- `tenant_id` + `terminal_id` + `receipt_number` + `business_timestamp`

---

## 10. Reliable Dispatch Pattern

To ensure no "accepted" submission is lost if the queue is unavailable:
1. **DB-Backed Dispatch Recovery:** Persist as `ACCEPTED`.
2. **Reconciliation Worker:** A background process scans for `ACCEPTED` records that haven't moved to `QUEUED` within 2 minutes and re-dispatches them.

---

## 11. Rollout & Rollback Strategy

### Phase A: Shadow Mode
Write raw intake to the new table but keep the old path as the source of truth. 
> [!NOTE]
> **Shadow Worker Behavior**: Performs full validation and transformation logic, but results are written to **isolated shadow logs/tables only**. It must **not** write to authoritative production business rows or trigger downstream side effects.

### Phase B: Tenant Pilot
Migrate 1-2 low-volume tenants to the new async path. Monitor lag and error rates.

### Rollback
- Feature flag toggle to revert to synchronous processing path.
- Non-destructive database migrations.
- **Rule:** Rollback must NOT require branch-destructive actions or force-pushes.

---

## 12. Implementation Phases

1. **Phase 1: Design Finalization** - State models, schema, validation boundaries.
2. **Phase 2: Durable Intake** - Schema migration, thin controller, raw persistence.
3. **Phase 3: Async Pipeline** - Worker logic, business validation, heavy writes.
4. **Phase 4: Recovery & DLQ** - Dispatch recovery, retry rules, dead-letter flow.
5. **Phase 5: Observability** - Dashboards, alerts, tenant-segmented views.
6. **Phase 6: Validation & Rollout** - Replay testing, Shadow mode, Pilot.

---

## 13. Acceptance Criteria (Measurable Targets)

- [ ] **Data Integrity**: `duplicate business inserts = 0` during replay/load testing.
- [ ] **Resilience**: `accepted-but-unprocessed` stranded records are auto-recovered within 2 minutes.
- [ ] **Intake Performance**: p95 intake latency < 100ms.
- [ ] **Queue Performance**: Queue lag stays below 30 seconds under target burst load.
- [ ] **Security**: 100% of requests verified against authenticated tenant-terminal mapping.
- [ ] **Observability**: End-to-end traceability using `trace_id` from HTTP request to final DB write.
- [ ] **POS Transparency**: Existing POS API contract is 100% preserved (No device changes).
