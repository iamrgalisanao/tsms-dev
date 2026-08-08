# Feature Specification: 100 Tenant Ingestion Resilience

**Feature Branch**: `remediate-backpressure-sharding-foundation`

**Created**: 2026-08-06

**Status**: Draft

**Input**: User description: "Create an implementation plan for release-blocker remediation so TSMS can handle approximately 100 tenants sending fast-paced transactions, using Aristotle and Backend Architect review plus Spec Kit documentation."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Protect Official Intake During Overload (Priority: P1)

As an operations owner, I need official transaction ingestion to reject or defer work before expensive database validation or large synchronous write paths, so burst traffic from 100 tenants does not exhaust DB/request capacity.

**Why this priority**: The official route is the live provider path and currently remains the largest resilience risk because overload gating happens after FormRequest validation and the route still uses a large synchronous transaction path.

**Independent Test**: Force overload/backpressure enforcement and submit to `/api/v1/transactions/official`; verify the request returns a deterministic rejection before terminal existence validation or transaction persistence occurs.

**Acceptance Scenarios**:

1. **Given** backpressure enforce mode and a saturated processing or intake queue, **When** a provider sends an official transaction submission, **Then** the system rejects with stable retry metadata before any DB-backed validation query.
2. **Given** a healthy system, **When** a provider sends a valid official submission, **Then** the system persists a durable intake record and dispatches processing only after the intake commit.
3. **Given** a repeated `submission_uuid`, **When** the same payload is resent, **Then** the system returns the existing outcome without duplicating transaction writes.
4. **Given** a repeated `submission_uuid` with a different checksum, **When** the payload is resent, **Then** the system returns a conflict and preserves the original intake record.

---

### User Story 2 - Bound Payload, Batch, and Transaction Work (Priority: P1)

As a platform operator, I need configurable request size and batch limits enforced early, so a single tenant or terminal cannot monopolize memory, validation time, queues, or DB write throughput.

**Why this priority**: Without maximum limits, a single large payload can bypass queue-depth protections and consume synchronous PHP/DB resources before the system can shed load.

**Independent Test**: Submit requests at, below, and above configured payload and batch limits; verify over-limit requests are rejected before persistence and boundary-limit requests proceed when healthy.

**Acceptance Scenarios**:

1. **Given** a configured maximum payload byte limit, **When** a request exceeds it, **Then** the system returns `413 Payload Too Large` before parsing or validation becomes expensive.
2. **Given** a configured maximum transaction count, **When** a batch exceeds it, **Then** the system rejects before per-transaction validation or persistence loops.
3. **Given** a request exactly at configured limits and the system is healthy, **When** it is submitted, **Then** it proceeds through the accepted ingestion path.

---

### User Story 3 - Shared Failure Controls and Safe Backpressure (Priority: P1)

As an incident responder, I need backpressure and circuit breaker decisions to be shared across app instances and fail safely during infrastructure degradation, so the system protects itself consistently under failure.

**Why this priority**: Current backpressure is queue-depth only and fail-open on Redis check errors; the circuit breaker state is local filesystem-backed and does not record ingestion outcomes.

**Independent Test**: Simulate Redis/backpressure failure and ingestion dependency failures across two app instances; verify shared breaker state and deterministic fail-closed/degraded responses in enforce mode.

**Acceptance Scenarios**:

1. **Given** Redis-backed circuit breaker state, **When** one app instance opens the breaker, **Then** another app instance observes the same open state.
2. **Given** enforce mode and failed backpressure health evaluation, **When** official ingestion is attempted, **Then** the request returns bounded `503` or configured rejection rather than accepting unlimited traffic.
3. **Given** client validation errors, **When** invalid payloads are submitted, **Then** the breaker does not count them as infrastructure failures.
4. **Given** successful half-open probes, **When** dependencies recover, **Then** breaker state closes according to configured thresholds.

---

### User Story 4 - Fair Multi-Tenant Queue Processing (Priority: P2)

As a platform operator, I need deterministic sharding, worker isolation, and tenant/terminal fairness, so one noisy tenant cannot starve the other 99 tenants.

**Why this priority**: Queue routing has improved, but remaining `% 8` paths, shared staging workers, and lack of tenant fairness still undermine multi-tenant resilience.

**Independent Test**: Run a staging load test with one hot tenant and 99 normal tenants; verify normal tenants continue to receive bounded latency and processing progress.

**Acceptance Scenarios**:

1. **Given** configured shard counts, **When** any ingestion dispatch path queues work, **Then** it uses the shared queue router and no hardcoded `% 8` logic.
2. **Given** staging Horizon is configured, **When** workers start, **Then** intake, processing, low, notifications, and reporting queues have independent worker pools.
3. **Given** one tenant exceeds configured fairness limits, **When** other tenants continue sending normal traffic, **Then** the noisy tenant is rate-limited without starving the rest.

---

### User Story 5 - Operational Readiness for 100-Tenant Load Test (Priority: P2)

As an operations team, I need dashboards, alerts, runbooks, and load-test gates before claiming readiness, so failures during staging are diagnosable and reversible.

**Why this priority**: A controlled staging load test is acceptable only if the team can observe queue health, DB pressure, rejection rates, and per-tenant skew in real time.

**Independent Test**: Execute dashboard and alert drills before load testing; verify each required signal appears with tenant, terminal, route, shard, and correlation context.

**Acceptance Scenarios**:

1. **Given** staging traffic, **When** ingestion requests are accepted, rejected, or failed, **Then** logs and metrics include tenant, terminal, route, shard, correlation ID, decision, and reason.
2. **Given** queue age, DB lock waits, breaker open state, or tenant skew breaches thresholds, **When** the condition occurs, **Then** alerts fire and link to the relevant runbook.
3. **Given** a 100-tenant staging load test, **When** the test completes, **Then** queue depth, DB latency, failed jobs, and unrecoverable intake records remain within documented thresholds.

### Edge Cases

- Redis is unavailable during backpressure enforcement.
- DB is reachable but lock waits/deadlocks spike.
- Queue depth is low but oldest job age is high.
- A single tenant sends many small requests that do not breach payload limits.
- A single terminal sends repeated retries with the same idempotency identity.
- Provider resubmits the same `submission_uuid` with changed payload checksum.
- Batch size is exactly at the configured maximum.
- Payload size is exactly at the configured byte maximum.
- Circuit breaker receives validation failures that must not open infrastructure breaker state.
- Shard count changes between staging runs and remaps tenants.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST run an overload gate for official ingestion before any DB-backed FormRequest validation or expensive payload validation.
- **FR-002**: System MUST provide an async official intake boundary that persists a minimal durable intake record, returns acceptance, and dispatches downstream processing only after commit.
- **FR-003**: System MUST preserve idempotency for repeated `submission_uuid`, repeated transaction IDs, duplicate receipts, and retries after partial processing.
- **FR-004**: System MUST return conflict for repeated `submission_uuid` with different payload checksum.
- **FR-005**: System MUST enforce configurable maximum payload byte size before expensive parsing, validation, or persistence work.
- **FR-006**: System MUST enforce configurable maximum batch transaction count before per-item validation or persistence loops.
- **FR-007**: System MUST avoid long DB transactions spanning full official batch processing.
- **FR-008**: System MUST split writes into short transactional units with retry-safe job behavior and recoverable intermediate states.
- **FR-009**: System MUST ensure backpressure rejection status, JSON body, and `Retry-After` header use the same clamped retry value, on every path that produces a backpressure rejection or degraded response — including official intake-queue overload (not only processing-queue overload).
- **FR-010**: System MUST evaluate queue depth, Redis health, worker drain status, and DB health before accepting ingestion when enforcement is enabled.
- **FR-011**: System MUST fail closed or return a bounded degraded response when backpressure health cannot be evaluated in enforce mode.
- **FR-012**: System MUST store circuit breaker state in shared Redis.
- **FR-013**: System MUST record circuit breaker success/failure outcomes around protected ingestion dependencies.
- **FR-014**: System MUST distinguish infrastructure/system failures from client validation failures for breaker accounting.
- **FR-015**: System MUST use `IngestionQueueRouter` as the single, exclusive shard-selection mechanism for all intake and processing dispatch paths; no code path may compute a shard/queue independently via a second, divergent algorithm.
- **FR-016**: System MUST remove hardcoded `% 8` shard calculations from ingestion dispatch paths.
- **FR-016a**: System MUST NOT silently orphan already-queued jobs when configured shard count changes; a shard-count change MUST be preceded by drain/verification of the shard queues being removed, with a documented rollback path.
- **FR-017**: Horizon staging MUST run workers for every configured intake and processing shard.
- **FR-018**: Horizon staging MUST separate intake, processing, low-priority, notifications, and reporting worker pools.
- **FR-019**: System MUST enforce per-tenant and per-terminal fairness controls before enqueue.
- **FR-020**: System MUST emit stable machine-readable rejection reasons for global backpressure, tenant rate limit, terminal rate limit, payload limit, and batch limit.
- **FR-021**: System MUST expose operational signals for queue depth, queue age, drain rate, request latency, rejection rate, DB pressure, breaker state, and tenant skew.
- **FR-022**: System MUST provide runbooks for overload enforcement, breaker operation, Horizon scaling, failed-job replay, abusive tenant handling, and Redis degradation.

### Key Entities *(include if feature involves data)*

- **Ingestion Request**: Durable record of accepted or rejected official ingestion attempt; includes tenant, terminal, source, status, payload hash, idempotency identity, timestamps, rejection reason, and correlation ID.
- **Backpressure Decision**: Per-request decision containing decision type, queue, shard, queue depth, oldest job age, DB health state, Redis health state, retry value, and reason.
- **Circuit Breaker State**: Shared Redis state tracking breaker name, state, consecutive failures, rolling failure rate, opened time, half-open probes, and last success/failure timestamps.
- **Fairness Bucket**: Rate or concurrency budget scoped globally, per tenant, and per terminal.
- **Queue Route**: Deterministic mapping from tenant to intake/processing queue using configured shard counts.
- **Operational Signal**: Metric/log/event used for dashboards, alerts, and load-test gates.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: During enforced overload, official ingestion rejects before DB existence validation and before transaction persistence.
- **SC-002**: A controlled 100-tenant staging load test completes with bounded queue growth and no unrecoverable intake records.
- **SC-003**: In a hot-tenant test, the 99 normal tenants continue to make processing progress while the noisy tenant receives fairness rejections.
- **SC-004**: Backpressure rejection responses always have matching `Retry-After` header and JSON retry seconds.
- **SC-005**: No ingestion dispatch path uses hardcoded `% 8` shard calculation.
- **SC-006**: Circuit breaker state is shared across app instances and opens/closes according to configured thresholds.
- **SC-007**: Batch and payload limits reject over-limit requests before persistence and accept boundary-limit requests when healthy.
- **SC-008**: Staging dashboards show p50/p95/p99 latency, queue depth/age per shard, worker drain rate, DB lock/deadlock pressure, rejection rate by reason, and per-tenant skew.
- **SC-009**: Alerts fire during drills for queue age breach, failed job spike, DB lock/deadlock spike, circuit breaker open, Redis unavailable, latency breach, and tenant skew.

## Assumptions

- Current Laravel, Sanctum, Redis queue, Horizon, and MySQL foundations remain in place.
- The first implementation target is controlled staging load-test readiness, not immediate production-like release.
- Official provider clients can tolerate a documented async `202 Accepted` transition or a compatibility mode that preserves response shape while moving expensive work async.
- Staging thresholds may be stricter than production thresholds and must be config-driven.
- Webapp forwarding remains removed and is out of scope.
- Financial calculation/business-rule changes are out of scope except where required for retry-safe ingestion.
- Observability may use the current logging/metrics stack initially, but signals must be durable enough for incident analysis.
