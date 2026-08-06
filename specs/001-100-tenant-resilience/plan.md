# Implementation Plan: 100 Tenant Ingestion Resilience

**Branch**: `remediate-backpressure-sharding-foundation` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-100-tenant-resilience/spec.md`

## Summary

Build on the current backpressure/sharding foundation to make TSMS ready for a controlled 100-tenant staging load test. The plan prioritizes moving official ingestion behind a cheap overload gate and async intake boundary, bounding payload/batch work, shortening DB transactions, making breaker/backpressure behavior shared and fail-safe, aligning queue routing everywhere, isolating Horizon workers, adding tenant fairness, and documenting observability/runbook gates.

## Technical Context

**Language/Version**: PHP 8.x, Laravel 11

**Primary Dependencies**: Laravel HTTP/FormRequest validation, Sanctum, Redis queues, Horizon, MySQL, cache/logging facilities

**Storage**: MySQL for durable ingestion/transaction records; Redis for queues, backpressure/fairness counters, shared circuit breaker state

**Testing**: Laravel PHPUnit feature/unit tests, targeted integration tests using test DB and Redis fakes or local Redis where required

**Target Platform**: TSMS backend API running in staging/production-like server environments

**Project Type**: Backend web service with queue workers

**Performance Goals**: Controlled staging resilience for approximately 100 active tenants sending bursty POS transactions; bounded queue age, bounded API latency, and no unrecoverable intake records during load test

**Constraints**: Preserve tenant isolation, terminal authorization, idempotency, existing provider compatibility where feasible, and `remove-webapp-forwarding` fallback branch safety

**Scale/Scope**: Official and batch transaction ingestion paths, processing dispatch, circuit breaker/backpressure behavior, Horizon worker topology, operational readiness

## Constitution Check

The local constitution template contains placeholders only, so no formal project gates are defined. This plan applies the effective gates from the architecture assessment:

- Test-first or test-backed remediation for each release blocker.
- No production-like release while P0/P1 resilience findings remain.
- Config-driven rollout with observe mode before enforce mode.
- Rollback path must remain available through the untouched fallback branch.

## Project Structure

### Documentation (this feature)

```text
specs/001-100-tenant-resilience/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── ingestion-api.md
│   └── operational-signals.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
├── Http/Controllers/API/V1/
├── Http/Middleware/
├── Http/Requests/
├── Jobs/
├── Models/
├── Services/
└── Support/

config/
├── horizon.php
└── tsms.php

database/
└── migrations/

routes/
└── api.php

tests/
├── Feature/
└── Unit/
```

**Structure Decision**: Keep the existing Laravel monolith structure. Add focused services, middleware, jobs, request classes, models, config, and tests in the existing directories rather than introducing new packages.

## Phase 0: Baseline and Scope Lock

**Goal**: Make current behavior measurable before changing the flow.

**Implementation Plan**:

- Document current official and batch ingestion paths.
- Capture baseline metrics for request rate by tenant/terminal, API latency, validation DB query volume, queue depth per shard, DB transaction duration, failed jobs, retries, dead letters, and Horizon worker saturation.
- Ensure every official ingestion request has a correlation ID in logs.

**Acceptance Criteria**:

- Dashboard or staging report shows intake requests, queue depth, DB latency, failed jobs, and tenant skew.
- Logs include correlation ID, tenant, terminal, route, shard, status, and latency.

## Phase 1: Protect Official Intake Boundary

**Goal**: Stop expensive DB validation and large synchronous writes before overload protection.

**Preferred Path**:

- Replace `TransactionController@storeOfficial` synchronous behavior with a small async intake boundary.
- Use cheap structural validation only before the overload gate.
- Run backpressure before any `exists:pos_terminals,id` validation.
- Persist a minimal durable intake record.
- Dispatch downstream validation/processing to tenant-routed intake queue after commit.
- Return `202 Accepted` with a submission/intake status reference.

**Compatibility Fallback**:

- If clients cannot yet accept async semantics, move overload/backpressure to middleware before FormRequest resolution.
- Avoid `exists` validation in the initial request path.
- Keep response shape mostly compatible while deferring expensive writes.

**Acceptance Criteria**:

- Official endpoint performs no terminal existence DB read before overload gate.
- Enforced overload returns `429` or `503` before DB validation.
- The synchronous request path does not execute the current large transaction/write path.
- Accepted requests enqueue to the expected intake shard after commit.

## Phase 2: Payload and Batch Limits

**Goal**: Bound memory, validation, queue, and DB fanout.

**Implementation Plan**:

- Add config-driven `max_payload_bytes`, `max_batch_count`, and optional `max_transaction_items`.
- Enforce payload bytes in middleware before controller work.
- Enforce batch count before per-item validation loops.
- Return `413` for payload byte violations and a stable validation error for batch count violations.

**Acceptance Criteria**:

- Oversized payloads are rejected before controller work.
- Oversized batches are rejected before validation loops and persistence.
- Boundary-limit requests are accepted when the system is healthy.

## Phase 3: Shorten DB Transactions

**Goal**: Reduce lock time, deadlock risk, and request/worker saturation.

**Implementation Plan**:

- Split current official write path into smaller units: intake persistence, validation/enrichment, transaction upsert, side effects.
- Keep DB transactions scoped to atomic writes only.
- Move logging, notifications, cache updates, and external side effects outside critical DB transactions.
- Add or confirm indexes for tenant/terminal lookup, transaction external IDs, ingestion status, and received/created timestamps.

**Acceptance Criteria**:

- No external calls or queue dispatches occur inside critical DB transactions unless explicitly justified.
- Transaction duration p95 meets the staging target selected during Phase 0.
- Deadlocks and lock wait timeouts remain near zero during load test.

## Phase 4: Shared Circuit Breaker and Fail-Safe Backpressure

**Goal**: Make overload decisions consistent across app nodes and safe during Redis/dependency degradation.

**Implementation Plan**:

- Move circuit breaker state to Redis.
- Track consecutive failures, rolling failure rate, opened time, half-open probe count, and last success/failure timestamps.
- Build ingestion breaker outcome accounting; no current ingestion code path records success/failure against the `transaction-intake` breaker key.
- Record success/failure around Redis enqueue, DB write, validation/enrichment job, and downstream processing job.
- In enforce mode, fail closed or return bounded degraded response if backpressure health cannot be evaluated.
- Ensure validation/client errors do not open infrastructure breaker state.

**Acceptance Criteria**:

- Multiple app instances observe the same breaker state.
- Breaker opens/closes according to configured thresholds.
- Redis/backpressure check failure in enforce mode does not silently allow unbounded ingestion.

## Phase 5: Queue Sharding Consistency

**Goal**: Remove hardcoded shard assumptions.

**Implementation Plan**:

- Replace remaining `% 8` dispatch paths with `IngestionQueueRouter`.
- Add static or architecture test that fails if ingestion dispatch code reintroduces `% 8`.
- Document that changing shard count remaps tenants unless consistent hashing is adopted.

**Acceptance Criteria**:

- No ingestion dispatch path uses `% 8`.
- Intake and processing queue names are generated through router/config only.
- Router tests cover configured shard counts.

## Phase 6: Horizon Worker Isolation and Scaling

**Goal**: Prevent low-priority work from consuming ingestion capacity.

**Implementation Plan**:

- Split staging supervisors for intake, processing, low, notifications, and reporting.
- Increase processing worker capacity only after DB transaction shortening and DB connection limits are reviewed.
- Configure queue-specific timeouts, tries, and failed-job handling.

**Acceptance Criteria**:

- Processing shards have dedicated workers.
- Low/notification queues cannot consume processing worker slots.
- Horizon shows no starvation during 100-tenant load test.

## Phase 7: Tenant and Terminal Fairness

**Goal**: Prevent one tenant or terminal from monopolizing capacity.

**Implementation Plan**:

- Add Redis token-bucket or leaky-bucket limits for global, tenant, and terminal scopes.
- Apply fairness before enqueue.
- Emit stable rejection reasons for tenant and terminal throttling.
- Support environment and tenant-tier overrides.

**Acceptance Criteria**:

- A noisy tenant cannot consume all intake capacity.
- A noisy terminal cannot block other terminals in the same tenant.
- Metrics expose top rejected tenants/terminals.

## Phase 8: Observability, Alerts, Runbooks, and Load Test Gates

**Goal**: Make staging failure modes visible and reversible.

**Implementation Plan**:

- Add dashboards for API latency, request count, rejection reason, queue depth/age, job duration/failure rate, DB transaction duration, DB lock waits/deadlocks, Redis latency/errors, breaker state, and tenant skew.
- Add alerts for queue age, failed job spikes, DB lock/deadlock spikes, breaker open, Redis unavailable, latency breach, and tenant skew.
- Add runbooks for breaker operation, Horizon scaling, failed-job replay, disabling abusive tenant/terminal, and Redis degradation.
- Run load tests in observe mode, then enforce mode.

**Acceptance Criteria**:

- Staging load test can be monitored live.
- Every rejection and failed job has reason, tenant, terminal, shard, and correlation ID.
- Alert drills pass before production-like release.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Async official ingestion boundary | Protects DB/request capacity during 100-tenant bursts | Keeping synchronous `storeOfficial` preserves the largest release blocker |
| Redis-backed breaker/fairness state | Required for multi-instance consistency | Local filesystem/cache-only state cannot coordinate app nodes |
| Dedicated Horizon supervisors | Required to isolate ingestion capacity | Shared staging worker pool lets low/notification jobs starve processing |
