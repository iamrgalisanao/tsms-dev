# Feature Specification: Ingestion Hardening (TSMS Defect Revalidation Follow-up)

**Feature Branch**: `003-ingestion-hardening`

**Created**: 2026-10-26

**Status**: Draft

**Input**: User description: "Ingestion hardening from the TSMS defect revalidation (Oct 26, 2026). Fix revalidated weaknesses on the POS ingestion path without changing the behavior of the working official intake flow. In scope: retire the legacy batch endpoint; correct ingest outcome classification; rejection observability and alerting; circuit-breaker accounting; timestamp sanity and late-sale flagging; remove the net_sales zero fallback; ingest log hygiene. Out of scope: terminal 46 / hardware identity (TSMS-01, TSMS-02), historical backfill, rejected-intake replay, financial reconciliation (TSMS-09), wider dead-code removal. The official intake contract must be preserved and no migration may rewrite production data."

Source findings: the "TSMS Defect Revalidation & Remediation Report" (2026-10-26). IDs in brackets (TSMS-nn, "new n") refer to that report.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Only one, fully validated way to submit sales (Priority: P1)

As the TSMS operator, I need every POS sale to enter through the official intake path, so that no sale reaches the ledger without the hardware check, the intake record, checksum validation and mandatory monetary fields. The legacy batch endpoint is retired. [TSMS-05, new 1]

**Why this priority**: The batch endpoint is a live bypass of every intake control. It is unused (no traffic on staging since June 2026), so retiring it removes the risk at almost no cost.

**Independent Test**: Send an authenticated request to the batch endpoint and confirm it is refused with a clear machine-readable response naming the official endpoint, and that no transaction or job is created. Confirm official intake still works unchanged.

**Acceptance Scenarios**:

1. **Given** an authenticated terminal, **When** it submits to the batch endpoint, **Then** it receives a "gone" response in JSON that names the official endpoint, and no transaction row, intake row or processing job is created.
2. **Given** the legacy-API environment flag is enabled, **When** a request reaches the old unauthenticated batch route, **Then** it receives the same "gone" response.
3. **Given** a valid official submission, **When** it is sent after the change, **Then** the response status and body are identical to before the change.

---

### User Story 2 - Every intake outcome matches the ledger (Priority: P1)

As finance and operations staff, I need an intake's final status to say truthfully what happened to the sale, so that a sale already in the ledger is never reported as a permanent failure, and a real failure is never hidden. [TSMS-07, TSMS-04, new 4]

**Why this priority**: 6,572 staging intakes are marked permanently failed although the cause is a duplicate or a collision, and constraint errors are silently swallowed. This corrupts every downstream count of failures.

**Independent Test**: Submit sales that hit each uniqueness rule (same transaction ID same tenant; same receipt same day; same transaction ID from a different tenant; a row that violates a required-field rule) and confirm each intake ends with the correct, distinct outcome.

**Acceptance Scenarios**:

1. **Given** a sale already recorded for the same tenant, **When** the same transaction ID is ingested again, **Then** the outcome is "already processed" and the intake is not marked failed.
2. **Given** a recorded sale with the same terminal, receipt number and business date, **When** a resubmission with a new transaction ID is ingested, **Then** the outcome is "duplicate receipt" and points to the existing sale.
3. **Given** a transaction ID already used by a different tenant, **When** it is ingested, **Then** the outcome is a distinct "cross-tenant transaction ID collision" failure, the existing tenant's data is neither read back to nor changed for the submitter, and an operational warning is raised.
4. **Given** a sale whose data violates a database rule, **When** it is ingested, **Then** the outcome is a genuine failure carrying a reason code, never a silent ignore.
5. **Given** a brand-new valid sale, **When** it is ingested, **Then** it is recorded exactly as before.

---

### User Story 3 - Operators are alerted to rejection spikes (Priority: P2)

As an operator, I need to see rejections by tenant, terminal and reason, and be alerted when a terminal is rejected repeatedly or stops recording sales, so that a problem like 363,000 silent rejections is caught within an hour, not a month. [TSMS-03]

**Why this priority**: Detection is the gap that turned a configuration issue into a month-long data gap. It does not change ingestion behavior.

**Independent Test**: Generate rejections for one terminal above the threshold and confirm a single alert names the tenant, terminal, reason and count; confirm per-terminal counts are queryable; confirm one submission can be traced from request to recorded sale.

**Acceptance Scenarios**:

1. **Given** rejections are recorded, **When** an operator views intake metrics, **Then** counts are available per tenant, per terminal and per reason code.
2. **Given** a terminal exceeds the rejection threshold within the alert window, **When** the scheduled check runs, **Then** one alert is raised naming tenant, terminal, reason and count, and it is not repeated every run while the condition persists.
3. **Given** an active terminal that had accepted sales recently but none in the last 24 hours while still submitting, **When** the scheduled check runs, **Then** an alert is raised.
4. **Given** an accepted submission, **When** an operator searches logs by its correlation ID, **Then** they find the intake, the processing and the recorded-sale log lines.

---

### User Story 4 - Overload protection reflects real outages only (Priority: P2)

As an operator, I need the ingestion circuit breaker to count only genuine infrastructure success and failure, and see data-level ingest failures as their own signal, so that monitoring is trustworthy. [TSMS-06]

**Why this priority**: The breaker shields all tenants; wrong accounting can close it early during recovery or mask problems.

**Independent Test**: Exercise ingestion with an infrastructure failure, a data-level failure, a duplicate and a success, and confirm the breaker sees only the infrastructure outcomes, background successes do not close an open breaker, and data-level failures increment their own metric.

**Acceptance Scenarios**:

1. **Given** a data-level ingest failure, **When** it completes, **Then** the breaker records neither success nor failure, and the data-failure metric increments.
2. **Given** the breaker is open or recovering (half-open), **When** a background ingest succeeds, **Then** the breaker state is unchanged; only the request-path recovery probes decide when it closes.
3. **Given** a genuine infrastructure failure, **When** it occurs, **Then** the breaker records a failure exactly as today.

---

### User Story 5 - Impossible dates rejected, late sales flagged (Priority: P2)

As finance staff, I need sales with impossible dates refused at intake and late-arriving sales flagged with their arrival delay, so that backlog replays are visible and bad dates never enter the ledger. [TSMS-08, new 3]

**Why this priority**: Zero-dates already exist in staging data; backlog arrivals are currently invisible.

**Independent Test**: Submit a sale dated `0000-00-00`, one with an unparseable date, one far in the future, one 30 days old and one current; confirm the first three are rejected with a reason code and the last two are accepted, with the old one flagged as late and its delay recorded.

**Acceptance Scenarios**:

1. **Given** enforcement is enabled and a submission has an impossible or unparseable transaction date, **When** it is received, **Then** it is rejected at intake with a specific reason code and recorded as rejected.
2. **Given** enforcement is enabled and a transaction is dated further in the future than the allowed tolerance, **When** it is received, **Then** it is rejected with a specific reason code; in observe mode it is accepted and counted as a would-be rejection.
3. **Given** a valid sale older than the late threshold, **When** it is received, **Then** it is accepted exactly as today and additionally flagged as late with its arrival delay recorded.
4. **Given** a valid current sale, **When** it is received, **Then** behavior is unchanged.

---

### User Story 6 - Missing net sales never become zero (Priority: P3)

As finance staff, I need ingestion to refuse a sale with no net-sales value rather than record it as zero. [TSMS-05]

**Why this priority**: Intake validation already requires the field, so this closes a defense-in-depth gap for any non-intake caller.

**Independent Test**: Call ingestion directly with a payload lacking net sales and confirm it fails with a reason code instead of recording a zero-value sale.

**Acceptance Scenarios**:

1. **Given** a payload with no net-sales value, **When** it is ingested, **Then** it fails as an invalid payload and nothing is recorded.
2. **Given** a payload with net sales of exactly 0, **When** it is ingested, **Then** it is recorded as zero (a valid zero is still allowed).

---

### User Story 7 - Logs carry references, not payloads (Priority: P3)

As the system owner, I need ingestion logs to identify a sale by references (IDs, correlation ID, reason) rather than dumping full payloads or rows. [TSMS-10]

**Why this priority**: Reduces unnecessary data exposure and log volume; no functional change.

**Independent Test**: Trigger each ingest log path (invalid payload, duplicate, failure) and confirm log entries contain reference fields and no full payload, row or original-payload content.

**Acceptance Scenarios**:

1. **Given** any ingest-path log event, **When** it is written, **Then** it contains tenant, terminal, transaction reference, correlation ID and reason, and no full payload or row.

---

### Edge Cases

- Two submissions of the same new sale processed at the same moment: exactly one is recorded; the other ends as "already processed", not failed.
- A duplicate-key error on a uniqueness rule not anticipated by the classifier: treated as a genuine failure with the rule's name in the reason, never ignored.
- A resubmission where the receipt matches but the business date differs (e.g. timestamp drift over midnight): recorded as a new sale only if no uniqueness rule is hit; the existing ±24h receipt check behavior is preserved.
- Retired batch endpoint called with an oversized or malformed body: still the same "gone" response, with no validation work done.
- Alert check runs while the metrics store is unavailable: the check logs the failure and does not crash the scheduler or block ingestion.
- Late threshold or future tolerance not configured: safe defaults apply.
- Batch retirement while a provider unexpectedly still uses it: they receive an actionable response; ops can see the attempts in logs.

## Requirements *(mandatory)*

### Functional Requirements

**Batch retirement (US1)**
- **FR-001**: The authenticated batch submission endpoint MUST respond with a "gone" status and a JSON body naming the official submission endpoint, and MUST create no transaction, intake record or job.
- **FR-002**: The flag-gated legacy batch route MUST behave the same as FR-001.
- **FR-003**: Each refused batch call MUST be logged with terminal and tenant references so continued use is visible.

**Outcome classification (US2)**
- **FR-004**: Ingestion MUST NOT use an insert mode that silently discards rows on constraint or data errors.
- **FR-005**: When a uniqueness rule blocks a new sale, ingestion MUST identify which rule and classify the outcome as: already processed (same tenant, same transaction ID), duplicate receipt (same tenant, terminal, receipt, business date), cross-tenant transaction ID collision, or genuine failure.
- **FR-006**: "Already processed" and "duplicate receipt" outcomes MUST map to the same intake final statuses they map to today (processed / duplicate), not to permanent failure.
- **FR-007**: A cross-tenant collision MUST end as a failure with its own reason code, MUST NOT return or modify the other tenant's data, and MUST emit a warning naming both tenants' IDs and the transaction ID.
- **FR-008**: Concurrent ingestion of the same sale MUST record it exactly once.

**Observability (US3)**
- **FR-009**: Every intake rejection MUST increment counters keyed by tenant, terminal and reason code.
- **FR-010**: A scheduled check MUST raise an alert when a terminal's rejections in the alert window exceed a configurable threshold, and when an active, still-submitting terminal has had no accepted sale for 24 hours (configurable).
- **FR-011**: Alerts MUST be de-duplicated so a persisting condition does not alert on every run (at most once per terminal and reason per cooldown period).
- **FR-012**: The submission correlation ID MUST be included in processing and ingest log entries for that submission.

**Circuit breaker (US4)**
- **FR-013**: Background ingestion MUST NOT record breaker success (it is never admitted as a recovery probe, so recording success would force-close an open breaker); breaker recovery MUST be decided only by the request-path probes, which already pass the probe reference.
- **FR-014**: Data-level failures and duplicate outcomes MUST NOT be recorded as breaker success or failure.
- **FR-015**: Data-level ingest failures MUST increment a dedicated metric.
- **FR-016**: Infrastructure-failure handling MUST remain as today.

**Timestamp sanity (US5)**
- **FR-017**: Intake MUST reject transaction timestamps that are unparseable, have a zero year/month/day, or fall before a configurable earliest date, with reason code `INVALID_TRANSACTION_TIMESTAMP`.
- **FR-018**: Intake MUST reject transaction timestamps later than receipt time plus a configurable future tolerance, with reason code `FUTURE_TRANSACTION_TIMESTAMP`.
- **FR-019a**: Timestamp checks MUST support an observe mode (default: accept as today, log and count would-be rejections) and an enforce mode (reject per FR-017/FR-018), switchable by configuration without a code change (with cached config this needs a config re-cache and worker/PHP-FPM restart). Staging evidence: 21 of 385,258 sales in 60 days were more than 24h in the future.
- **FR-019**: Valid sales older than a configurable late threshold MUST be accepted unchanged and flagged as late, with arrival delay recorded in a metric and on the intake log entry.

**Net sales (US6)**
- **FR-020**: Ingestion MUST treat a missing net-sales value as an invalid payload; an explicit zero remains valid.

**Logging (US7)**
- **FR-021**: Ingest-path log entries MUST NOT include the full submitted payload, the full row being inserted, or the original payload; they MUST include reference fields (tenant, terminal, transaction ID, receipt reference, correlation ID, reason).

**Preservation (all stories)**
- **FR-022**: For valid official submissions, HTTP status codes, response bodies, intake statuses and the recorded transaction values MUST be unchanged relative to main's TSMS-004 contract (200 `PENDING`/`ACCEPTED`; per-item processed replay). Only with `INGESTION_TS_MODE=enforce` may timestamp-invalid submissions receive 422.
- **FR-023**: No change in this feature may rewrite existing production rows; any schema change MUST be additive only.

### Key Entities

- **Intake record**: one submission attempt; holds received time, intake status, processing status, last error code and correlation ID. Gains the new rejection reason codes and late flag in its outcome data.
- **Transaction**: one recorded sale; unique by transaction ID (system-wide) and by tenant + terminal + receipt + business date.
- **Ingest outcome**: the classification of one ingestion attempt (recorded, already processed, duplicate receipt, cross-tenant collision, invalid payload, failure).
- **Rejection counter / alert**: counts by tenant, terminal, reason and window; an alert with cooldown per terminal and reason.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Zero sales can be recorded through any path other than official intake (verified by automated tests against the batch endpoint).
- **SC-002**: In tests covering every uniqueness case, 100% of intakes end with the correct distinct outcome and none of "already processed" or "duplicate receipt" cases are marked permanently failed.
- **SC-003**: A terminal producing rejections above threshold triggers an alert within one scheduled check interval (target: under 15 minutes), and no more than one alert per terminal and reason per cooldown.
- **SC-004**: 100% of existing official-intake tests pass unchanged, and the response for a valid submission is byte-for-byte identical in contract tests.
- **SC-005**: No impossible-date sale is accepted in tests; 100% of late sales are flagged with their delay.
- **SC-006**: No ingest-path log entry in tests contains a full payload or row.

## Assumptions

- The batch endpoint has no active users; providers were integrated against the official endpoint. "Gone" (HTTP 410) is the retirement response.
- Default thresholds (configurable): rejection alert at 100 rejections per terminal per hour; no-accepted-sale alert after 24 hours; alert cooldown 6 hours; future tolerance 24 hours (POS clock drift and local-time-with-Z providers make a tighter default risky); late threshold 1 day; earliest valid date 2020-01-01.
- Alerts use the system's existing operational notification and log channels; no new external integration.
- Late sales are accepted (POS offline backlogs are legitimate); only impossible dates are refused.
- Cross-tenant collisions are expected to be rare; a failed outcome plus warning is sufficient in this feature, without changing the system-wide uniqueness rule.
- Existing FAILED_PERMANENT rows stay as they are; reclassification is a separate, approved data task.
- Terminal identity (TSMS-01/02), replay tooling and reconciliation (TSMS-09) are handled in later features.
