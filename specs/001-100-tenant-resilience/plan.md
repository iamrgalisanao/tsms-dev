# Implementation Plan: 100 Tenant Ingestion Resilience

**Branch**: `remediate-backpressure-sharding-foundation` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-100-tenant-resilience/spec.md`

## Summary

Build on the current backpressure/sharding foundation to make TSMS ready for a controlled 100-tenant staging load test. The plan prioritizes moving official ingestion behind a cheap overload gate and async intake boundary, bounding payload/batch work, shortening DB transactions, making breaker/backpressure behavior shared and fail-safe, aligning queue routing everywhere, isolating Horizon workers, adding tenant fairness, and documenting observability/runbook gates.

## Feature Status (recorded decision)

**US1–US4 are complete and validated**: official intake overload protection, payload/batch bounding, shared circuit-breaker/backpressure behavior (including T028/T028a/T028b/T035/T036/T037), and fair multi-tenant queue processing (T038-T047) are all implemented, tested, and reviewed.

**US5 (Operational Readiness for 100-Tenant Load Test, T048–T061) is explicitly DEFERRED to a separate follow-up phase.** Rationale: US5 is a distinct operational-readiness scope — observability, dashboards, alert definitions, staging load drills, and final release gating — and keeping it separate avoids mixing implementation hardening with operational rollout work. T048–T061 remain open in `tasks.md` and must not be described or treated as completed. This branch may be finalized/characterized as "US1–US4 complete; US5 deferred," not as "feature complete."

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

- Add Redis fixed-window `INCR`+`EXPIRE` limits for global, tenant, and terminal scopes (see "Fairness Architecture" below).
- Apply fairness before enqueue.
- Emit stable rejection reasons for tenant and terminal throttling.
- Tenant-tier overrides are deferred/out of scope for this feature (see "Fairness Architecture" below).

**Acceptance Criteria**:

- A noisy tenant cannot consume all intake capacity.
- A noisy terminal cannot block other terminals in the same tenant.
- Metrics expose top rejected tenants/terminals.

### Fairness Architecture (approved decisions for T038/T044/T045/T046)

An architecture review of the remaining "Fair Multi-Tenant Queue Processing" work (T038, T044, T045, T046) resolved the following points. These are binding for implementation, not options to re-litigate:

**1. Redis primitive: fixed-window counters via `INCR`+`EXPIRE`, not token-bucket, not Lua.**
A rate window is a single atomic increment, not a multi-step state machine, so it does not warrant a new Lua script. Lua is reserved in this codebase for genuine multi-step state races — see the circuit breaker's half-open admission (`CircuitBreaker::HALF_OPEN_ADMISSION_SCRIPT`/`HALF_OPEN_TRANSITION_SCRIPT`), which needs compare-and-set semantics across several fields in one round trip. A fairness window needs neither: `INCR` a per-scope-per-window key, `EXPIRE` it on first increment (or via `SET ... EX` semantics), and compare against the configured limit.

**2. `IngestionQueueRouter` and the fairness service are separate abstractions — do not merge them.**
- `IngestionQueueRouter`: pure, I/O-free function answering "which queue" (routing). No Redis, no side effects — this is what T037's static test and `tests/Feature/TenantShardRoutingConsistencyTest.php` already assume and enforce.
- Fairness service (new, T044): answers "allow or throttle now" (admission). Performs Redis I/O. Consulted after routing is already decided, never influences which queue is chosen.

**3. Middleware order: payload-size → backpressure → circuit-breaker → fairness.**
Cheapest/most-global checks run first; the most granular/expensive per-tenant+terminal check runs last, and only for requests that already passed system-wide health gates. Concretely, on `/api/v1/transactions/official` and `/api/v1/transactions/batch` (`routes/api.php:180-186`): `ingestion.payload_size` → `ingestion.backpressure:processing` → `circuit.breaker:transaction-intake` → fairness (new, T045).

**4. Fairness fails OPEN on Redis failure — this deliberately diverges from `IngestionBackpressureService`'s fail-closed-in-enforce-mode convention (T034).**
Verified against real code: `CircuitBreaker::isAvailable()` (`app/Services/CircuitBreaker.php`) itself fails open on Redis errors, with an inline comment establishing that `IngestionBackpressureService`'s fail-closed aggregate check (T034) is the real fail-closed backstop for the whole ingestion path. Fairness sits as a peer/refinement above that backstop, not as the backstop itself. Fairness failing open on Redis loss does not leave the system unprotected — backpressure and the circuit breaker already gate the request. Fairness failing closed instead would reject 100% of tenants (not just the hot one) the moment fairness's Redis calls fail, recreating the exact starvation this feature exists to prevent.

**5. `Retry-After` derived from the active window's reset time.**
Mirrors the pattern already established for backpressure (T035, `IngestionBackpressureService`/`IngestionBackpressureMiddleware`) and the circuit breaker (T028b, `CircuitBreaker::retryAfterSeconds()`): the JSON body's `retry_after_seconds` and the `Retry-After` header MUST carry the same value, computed once, derived from when the active fixed window resets (not a fixed constant).

**6. Fairness is scoped per-tenant AND per-terminal, NOT per-shard.**
At the real configured shard count (`config('tsms.processing.shard_count')`, defaulting to 8 per `config/tsms.php:96` and `config/horizon.php`) and spec.md's ~100-tenant assumption, roughly 12-13 tenants collide per shard (100 / 8 ≈ 12.5). Shard-level limiting alone would still let one hot tenant starve its ~12 shard-mates. Fairness therefore checks tenant and terminal counters independently of, and in addition to, `IngestionQueueRouter`'s shard assignment.

**7. Tenant-tier overrides: DEFERRED / out of scope for this feature.**
spec.md's FR-019 requires only per-tenant and per-terminal fairness controls; no functional requirement or acceptance scenario in spec.md mandates per-tenant *tiers* as a MUST for this feature's acceptance criteria, and data-model.md's Fairness Bucket entity (below) carries no tier field. Rather than introduce a tier config schema, fallback order, and validation rules with no acceptance criterion requiring them, tenant-tier overrides are deferred to a future feature. T044 implements a single global/tenant/terminal limit set (config-driven, e.g. `config('tsms.fairness.*')`), with no per-tenant-tier branching. If a future feature needs tenant tiers, the natural extension is a config-file override map (e.g. `config('tsms.fairness.tenant_overrides')`) consulted before the global default — not a new database table — consistent with this feature's config-driven-rollout constitution gate; that extension is explicitly out of scope here.

**8. Tenant/terminal identity resolution — one authoritative order, matching the real ingestion path.**
Verified against real code (`app/Services/TransactionIntakeService.php:55-56`, `app/Http/Middleware/IngestionBackpressureMiddleware.php:19-21`, `routes/api.php:171,180-186`):
- `TransactionIntakeService::handleOfficialIntake()` resolves tenant as `$request->user() instanceof PosTerminal ? $request->user()->tenant_id : ($payload['tenant_id'] ?? 0)`, and resolves terminal identity as the authenticated `PosTerminal`'s own `id`.
- `IngestionBackpressureMiddleware::handle()` resolves tenant as `$request->user() instanceof PosTerminal ? $request->user()->tenant_id : $request->input('tenant_id')` (it has no terminal-scoped check today, so it never resolves terminal identity).
- These two are **not** a genuine divergence in practice: both use the same primary source (the authenticated `PosTerminal`'s `tenant_id`) and functionally equivalent fallbacks (`$payload['tenant_id']` and `$request->input('tenant_id')` read the same underlying request field). On the real routes, `auth:sanctum` and `abilities:transaction:create` (`routes/api.php:171,180`) already run before any of `ingestion.payload_size`, `ingestion.backpressure:processing`, `circuit.breaker:transaction-intake`, so `$request->user()` is guaranteed to be the authenticated `PosTerminal` by the time fairness middleware would run — the fallback branch is defensive only.
- **Fairness middleware MUST resolve identity as**: tenant_id = `$request->user()->tenant_id`, terminal_id = `$request->user()->id`, when `$request->user()` is instanceof `PosTerminal`; fallback tenant_id = `$request->input('tenant_id')`, fallback terminal_id = `$request->input('terminal_id')` (the terminal fallback is a new, symmetric extension of `IngestionBackpressureMiddleware`'s existing tenant-only fallback, since fairness is the first component that needs terminal identity at the middleware layer). This is the same tenant/terminal identity that is actually billed, queued, and routed by `TransactionIntakeService` and `IngestionQueueRouter`, so fairness never diverges from what it is protecting.
- **Unresolvable identity behavior**: if tenant_id is still not a positive integer after the fallback (malformed/missing payload before validation has run), fairness MUST skip its check (pass the request through) and let the later structural/FormRequest validation layer (`tenant_id => required|integer` in `TransactionIntakeService`'s structural rules) reject the request on its own. Fairness's job is throttling identified tenants, not identity validation — inventing a fairness-specific rejection for a missing identity would duplicate a check validation already owns and could produce a misleading rejection reason.
- The header-name divergence between `AttachCorrelationId` (`X-Request-Id`) and `TransactionIntakeService` (`X-Correlation-ID`) noted in data-model.md is a separate, already-flagged, pre-existing issue unrelated to tenant/terminal resolution and is not resolved by this decision.

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

## Revised Implementation Order (Remaining US3/US4 Work)

An architecture review of the remaining US3 (Shared Failure Controls and Safe Backpressure) and US4 (Fair Multi-Tenant Queue Processing) work found a live, already-active correctness bug that is independent of the rest of US3, plus gaps in circuit-breaker test coverage and the `Retry-After` contract. The remaining work is resequenced as follows:

1. **T040-T043 (sharding correctness fix)** — unblocked, do first. `TestTransactionController.php`, `RetryHistoryController.php`, `BulkGenerateTransactionsJob.php`, and `TestTransactionPipeline.php` all hardcode `% 8` for queue routing while `IngestionQueueRouter` (the canonical router used by the real Horizon queue family) computes `crc32((string)$tenantId) % $shardCount` for the same tenants. These two algorithms disagree today, so tenant traffic is already inconsistently sharded in production code paths — this is not a future risk to document, it is a present bug to fix.
2. **T028a (half-open circuit-breaker semantics decision)** — **resolved.** The decision (bounded concurrent probes, N=3, close on 2-of-3 successes, reopen on 2-of-3 failures, `half_open_generation`-based late-result handling) is recorded and approved in `adr/T028a-half-open-circuit-breaker-semantics.md`.
3. **T028 (real breaker unit tests, per T028a's decision)** — new `tests/Unit/RedisCircuitBreakerTest.php` targeting `App\Services\CircuitBreaker`, written against the approved half-open semantics in `adr/T028a-half-open-circuit-breaker-semantics.md`.
3a. **T028b (circuit-breaker `Retry-After` header)** — independent follow-up work, added after T028a's resolution surfaced this as a separate pre-existing gap; does not block and is not blocked by T028/T028a.
4. **T035 (Retry-After header fix + regression test)** — close the gap where `TransactionController::storeOfficial()` never attaches `Retry-After` for intake-queue-overload rejections/degraded responses produced by `TransactionIntakeService::handleOfficialIntake()`'s own aggregate check.
5. **T029-T031 bookkeeping** — citation-based completion only; real tests already exist (`IngestionBackpressureFailureModeTest.php`, `IngestionCircuitBreakerTest.php`, `IngestionBackpressureTest.php`) and were verified method-by-method rather than assumed complete.
6. **T036 (runbook)** — written last so it reflects final breaker/backpressure behavior instead of needing a rewrite after T028/T035 land.
7. **T037 (static test banning `% 8`)** — written after T040-T043 so it has a real "should never regress" baseline to protect, rather than being added to a codebase that still fails it.
8. **Fairness and Horizon supervisor split, in this exact order** (see "Fairness Architecture" above for the full decisions):
   1. **T044** (fairness service + config) — no blocking dependency, can start immediately.
   2. **T038** (feature tests against T044's interface).
   3. **T045** (wire fairness middleware into the real `/transactions/official` and `/transactions/batch` routes, after payload-size/backpressure/circuit-breaker).
   4. **T039** and **T046** — T046 must not be marked complete until T039 exists and passes; T046 remains separately gated on T020b as already noted above. Both gates apply together.
9. **T046a (implicit default-queue gap fix)** — added after T046, independent of it in scope but sequenced after so it doesn't complicate T046's own completion proof. Several live `ShouldQueue`/`ShouldBroadcast` classes (see tasks.md for the full list) have no explicit queue assignment and silently fall back to Redis's unconsumed `default` queue — a pre-existing bug found during T046's safety review, not caused by it. A follow-up architecture review found two of these classes make blocking outbound HTTP calls at real transaction-scale frequency, which the existing `low`/`notifications` queues cannot safely absorb without starving the fast, low-volume work already there — so this task's approved design adds a new dedicated `webhook-callbacks` queue/supervisor (staging: 1 process; production: 2 processes) alongside T046's existing four-supervisor split, rather than routing everything onto `low`/`notifications` as the initial draft assumed. This is an intentional, approved topology change, not scope creep.
10. **T047 (shard-count-change safety hardening)** — last, since it depends on the sharding fix (step 1) being in place before drain/rollback procedures can be written against a single canonical routing algorithm.

**Why sharding correction is sequenced first**: T040-T043 fix a live, already-active correctness bug that has no dependency on any other US3 work. Fixing it before finishing the rest of US3's tests and runbook avoids writing new circuit-breaker tests, a `Retry-After` regression test, and an operational runbook against a codebase that still contains an admitted queue-routing inconsistency — any of those artifacts could otherwise need rework once routing is unified.

**`App\Services\CircuitBreaker` vs `App\Models\CircuitBreaker`**: These are two unrelated things and must not be conflated.
- `App\Services\CircuitBreaker` is the real, Redis-backed breaker actually wired into ingestion via `app/Http/Middleware/CircuitBreakerMiddleware.php:22` and `routes/api.php:184-186`. It is the only source of truth for ingestion breaker state.
- `App\Models\CircuitBreaker` is an unrelated legacy DB-backed Eloquent model. `tests/Unit/CircuitBreakerTest.php` tests this legacy model, not the real breaker — it has zero bearing on ingestion breaker correctness. Two admin dashboard controllers (`app/Http/Controllers/API/V1/CircuitBreakerController.php` and `app/Http/Controllers/API/Dashboard/CircuitBreakerController.php`) also mistakenly read this legacy model and must not be treated as reflecting real ingestion breaker status. T036's runbook must document this distinction and the two dashboard controllers as known-stale surfaces.

## Risk Tracking

| Risk | Description | Status |
|------|-------------|--------|
| Inconsistent shard computation across the codebase | `% 8` hardcoded routing (`TestTransactionController.php`, `RetryHistoryController.php`, `BulkGenerateTransactionsJob.php`, `TestTransactionPipeline.php`) disagrees with `IngestionQueueRouter`'s `crc32((string)$tenantId) % $shardCount` used by the real Horizon queue family. Tenant traffic is inconsistently sharded in production paths today. | **Resolved and implemented (T040-T043).** All four call sites now dispatch via `IngestionQueueRouter::processingQueueForTenant()`, the single canonical routing mechanism, with `TenantShardRoutingConsistencyTest.php` proving each site resolves the same queue as the router for a given tenant and `TenantShardModuloStaticAnalysisTest.php` (T037) guarding against hardcoded `% 8` ever being reintroduced. |
| Half-open circuit-breaker thundering herd / premature reopen | `App\Services\CircuitBreaker::isAvailable()` allowed unlimited concurrent requests through during half-open, `recordSuccess()` closed on a single success, and `transitionToHalfOpen()` never reset `failure_count`, so one failure during the unthrottled half-open window could immediately reopen the breaker. | **Resolved and implemented.** T028a's bounded-probe decision (N=3, close on 2-of-3, reopen on 2-of-3, `half_open_generation`-based staleness) is implemented in `app/Services/CircuitBreaker.php` via three atomic Redis Lua scripts — `HALF_OPEN_TRANSITION_SCRIPT` (the open→half-open transition itself, added after a later review found the initial two-script implementation left this transition racy), `HALF_OPEN_ADMISSION_SCRIPT`, and `HALF_OPEN_OUTCOME_SCRIPT`. T028 (tests) is complete — see `tests/Unit/RedisCircuitBreakerTest.php` and the ADR's "Final implemented atomicity" section for the full design. |
| Missing `Retry-After` header on circuit-breaker OPEN/HALF_OPEN rejection responses | `CircuitBreakerMiddleware::handle()` returned a bare `response()->json([...], 503)` with no `Retry-After` header when the circuit was open or a half-open probe was rejected over the N=3 cap. Separate from the T035 intake-aggregate gap (below, also resolved). | **Resolved and implemented (T028b).** Both OPEN and over-cap HALF_OPEN rejections now carry a `Retry-After` header, computed once via `CircuitBreaker::retryAfterSeconds()` and applied identically to the header and the JSON body's `retry_after_seconds` field. |
| Missing `Retry-After` header on intake-queue-overload responses | `TransactionController::storeOfficial()` does not attach `Retry-After` when `TransactionIntakeService::handleOfficialIntake()`'s own aggregate check rejects/degrades due to intake-queue pressure, unlike the middleware-level processing-queue rejection path which does. Violates FR-009. | **Resolved and implemented (T035).** `TransactionController::storeOfficial()` now attaches `Retry-After` whenever the result payload carries `retry_after_seconds`, with a regression test asserting the header matches the JSON body for the intake-overloaded/processing-healthy case. |
| Shard-count reduction can silently orphan queued jobs | If `tsms.processing.shard_count` is reduced, literal `% 8` paths (and any consumer relying on a fixed shard count) could emit shard indices with no live Horizon consumer, permanently orphaning jobs in Redis with no error. | **Resolved and implemented (T047).** `app/Console/Commands/VerifyShardTopology.php` provides a read-only drain-verification check (ready/reserved/delayed depth per retired queue), with the full increase/decrease/rollback procedure documented in `docs/SHARD_COUNT_CHANGE_RUNBOOK.md`. |
| Admin dashboards read wrong circuit-breaker data source | `app/Http/Controllers/API/V1/CircuitBreakerController.php`, `app/Http/Controllers/API/Dashboard/CircuitBreakerController.php`, and the filesystem-based `dashboard.circuit-breakers` view all read stale/unrelated data instead of the real Redis-backed ingestion breaker state. | **Resolved and implemented (T036).** `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` documents all three legacy/misleading surfaces and instructs operators to use Redis inspection instead. |

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Async official ingestion boundary | Protects DB/request capacity during 100-tenant bursts | Keeping synchronous `storeOfficial` preserves the largest release blocker |
| Redis-backed breaker/fairness state | Required for multi-instance consistency | Local filesystem/cache-only state cannot coordinate app nodes |
| Dedicated Horizon supervisors | Required to isolate ingestion capacity | Shared staging worker pool lets low/notification jobs starve processing |
