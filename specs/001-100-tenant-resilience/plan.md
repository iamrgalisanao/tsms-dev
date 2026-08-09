# Implementation Plan: 100 Tenant Ingestion Resilience

**Branch**: `remediate-backpressure-sharding-foundation` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/001-100-tenant-resilience/spec.md`

## Summary

Build on the current backpressure/sharding foundation to make TSMS ready for a controlled 100-tenant staging load test. The plan prioritizes moving official ingestion behind a cheap overload gate and async intake boundary, bounding payload/batch work, shortening DB transactions, making breaker/backpressure behavior shared and fail-safe, aligning queue routing everywhere, isolating Horizon workers, adding tenant fairness, and documenting observability/runbook gates.

## Feature Status (recorded decision)

**US1–US4 are complete and validated**: official intake overload protection, payload/batch bounding, shared circuit-breaker/backpressure behavior (including T028/T028a/T028b/T035/T036/T037), and fair multi-tenant queue processing (T038-T047) are all implemented, tested, and reviewed.

**US5 (Operational Readiness for 100-Tenant Load Test, T048–T061) is explicitly DEFERRED to a separate follow-up phase.** Rationale: US5 is a distinct operational-readiness scope — observability, dashboards, alert definitions, staging load drills, and final release gating — and keeping it separate avoids mixing implementation hardening with operational rollout work. T048–T061 remain open in `tasks.md` and must not be described or treated as completed. This branch may be finalized/characterized as "US1–US4 complete; US5 deferred," not as "feature complete."

A separate provider-aware VAT/report-correction gap was identified during delivery and is tracked outside this feature at `docs/specs/report-vat-correction-coverage.md`; financial calculation and reporting-rule changes remain outside the scope of US1–US5.

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

### Phase 8 Detailed Implementation Plan (US5: Gate 0, Work Units, Commit Groups)

**Status**: Approved for implementation after three rounds of architecture review (initial design, plan-correction review, final read-only validation against real source). This subsection supersedes Phase 8's high-level sketch above with the concrete, reviewed plan; Phase 8's own Goal/Acceptance Criteria above remain the authoritative acceptance target. T048 and T052–T056 are covered by this plan. **T049–T051 (staging alert drill, failed-job replay drill, 100-tenant load test) and T061 (final readiness sign-off) remain explicitly out of scope** — they require a live staging environment and human execution, and only become runnable once this plan's work lands. T059's full completion also depends on T049–T051 actually running.

This work is classified **high risk**: it touches two new Redis/Lua-backed structures, per-tenant enforcement behavior, ingestion rejection-path changes, shared observability contracts, and circuit-breaker/backpressure instrumentation. It is gated by Gate 0 below, not started directly.

#### Gate 0 — Pre-implementation gates (before any work unit starts)

1. **Architecture validation** of this plan (satisfied — see Status above).
2. **Regression-impact review**: confirmed which existing tests/behaviors each work unit touches (ingestion middleware chain, `IngestionFairnessService`, `CircuitBreaker`, `ObservabilityController`) before changing them — see each work unit's Files list below.
3. **Baseline verification** — run and record the accepted baseline **before** any code change:
   ```bash
   php artisan test --testsuite=Unit
   php artisan test --testsuite=Feature
   ```
   plus the following named targeted suites specifically (already included in the full `Feature`/`Unit` run above, called out because they are the direct regression surface for this work): `tests/Feature/IngestionBackpressureTest.php`, `tests/Feature/IngestionCircuitBreakerTest.php`, `tests/Feature/IngestionFairnessTest.php`, `tests/Unit/RedisCircuitBreakerTest.php`, `tests/Feature/TenantShardRoutingConsistencyTest.php`, `tests/Feature/HorizonSupervisorSeparationTest.php`; and `scripts/verify-rollback-branch.sh` to confirm the `remove-webapp-forwarding` fallback path is intact. (`phpunit.xml` defines only the two suites `Unit` and `Feature` — there is no separate "route/provider/license" suite; the full `Feature` run is the real substrate for that regression concern, e.g. `tests/Feature/PosIngestionLicenseEnforcementTest.php` is part of it.) This produces the reference snapshot WU10 diffs against later — it is not merely "run early."
4. **Commit-group confirmation**: the nine logical delivery units below, confirmed before starting.

#### Architecture Invariants (binding for all work units, not options to re-litigate)

1. **Fail-open primacy**: any new fairness/override Redis check (WU5) fails to `inherit`/allow on error. The only fail-closed backstop for the ingestion path remains `IngestionBackpressureService`'s aggregate check in `enforce` mode (per Fairness Architecture point 4 above) — no new component introduced by this plan may independently fail closed.
2. **Single-key Lua**: every new Redis Lua script (WU2's sample-trim, WU4's ranking-cap eviction, WU5's override set/clear) invokes `eval($script, 1, $key, ...)` — exactly one key — matching `CircuitBreaker`'s three existing scripts.
3. **One correlation-ID source of truth**: after WU1, every ingestion touchpoint reads only `$request->attributes->get('correlation_id')` (set once by `AttachCorrelationId`); no service re-derives from `X-Request-Id`/`X-Correlation-ID` headers independently.
4. **Routing/fairness separation preserved**: `IngestionQueueRouter` remains I/O-free (per Fairness Architecture point 2) and is never consulted for admission decisions; the new override service never computes or returns a queue name.
5. **Bounded cardinality**: every new Redis structure (distribution samples, tenant/terminal ranking, per-tenant override keys) carries an explicit member/key cap and a TTL, with defined behavior when the cap is exceeded (reject or coalesce into a fixed `other`/eviction rule — not silent unbounded growth).
6. **Read-only observability**: no WU7 endpoint mutates override state or any other ingestion state — GET-only, status-inspection only.
7. **Single owning commit per limit**: any new hard-coded operational limit is defined in the same commit that enforces it (see WU5 correction below) — never split across commits with enforcement landing before the value exists.

#### Commit / Delivery-Unit Rule (nine commits)

Delivery units are logical groupings, not one commit per work unit. WU6 (synthetic emission tests, T048) is not a standalone commit — its tests travel with the work unit they verify.

1. Correlation ID normalization and shared log context (WU1)
2. Metrics distribution infrastructure (WU2)
3. DB-pressure instrumentation (WU3)
4. Ingestion operational metrics — rejection/queue/breaker/bounded skew (WU4)
5. Tenant fairness override, including its own config (WU5)
6. Observability endpoints (WU7)
7. Alert definitions/configuration (WU8)
8. Runbooks (WU9)
9. Spec Kit status sync (WU11)

Runbooks and Spec Kit sync are kept as separate final commits: status sync only lands after everything else is independently verified, matching the audit pattern already used for US1–US4.

#### Work Units

**WU1 — Correlation ID normalization and shared log context (T052 foundation)**
Scope narrowed to the verified gaps only — three of four ingestion middleware (`IngestionPayloadSizeMiddleware`, `IngestionBackpressureMiddleware`, `IngestionFairnessMiddleware`) already read `$request->attributes->get('correlation_id') ?: $request->header('X-Request-Id')` correctly and MUST NOT be modified by this work unit. The real gaps are:
- `TransactionIntakeService::handleOfficialIntake()` independently reads `X-Correlation-ID` into `$traceId`/`trace_id` (the divergence already flagged in data-model.md and Fairness Architecture point 8's closing note) — change this to read the canonical `correlation_id` request attribute instead.
- `CircuitBreakerMiddleware` references neither header today — add the canonical attribute read.
Deterministic precedence:
```
Canonical internal field: request attribute `correlation_id` (set by AttachCorrelationId)

Ingress precedence:
1. existing trusted request attribute (if already set upstream)
2. X-Request-Id
3. X-Correlation-ID (compatibility fallback only, for any external
   integration still sending this header)
4. generated UUID

If both X-Request-Id and X-Correlation-ID are present with different
values: prefer X-Request-Id, log a bounded one-line warning noting the
mismatch, never let downstream services pick independently.

Response: always emit one canonical response header for the chosen value.
Logging: every service reads only the normalized request attribute —
no service re-derives correlation ID from raw headers itself.
```
One correlation-ID source of truth is preserved after this work unit (Invariant 3).
Introduce one shared log-context helper (trait or value object) so every ingestion touchpoint builds `tenant_id`/`terminal_id`/`route`/`shard`/`correlation_id`/`decision`/`reason` the same way.
Files: `app/Services/TransactionIntakeService.php`, `app/Http/Middleware/CircuitBreakerMiddleware.php`, `app/Jobs/ProcessTransactionIntakeJob.php` (thread `trace_id` from the intake record into job logs), `app/Services/IngestionQueueRouter.php` (add a shard-index-returning method).

**WU2 — MetricStore abstraction and bounded distributions (T053 foundation)**
Storage-interface split — do not bolt sorted-set/Lua operations onto the generic `Metrics` facade or force counters/gauges onto Redis-specific APIs:
```
Metrics (public facade — unchanged incr/decr/timing/bucket/get/snapshot API)
    ↓
MetricStore interface
    ├── existing Cache-backed counter/gauge store (unchanged behavior)
    └── new Redis distribution store (percentile sampling)
```
Verified safe: `Metrics::incr/decr/timing/bucket/get/snapshot` is 100% `Cache::`-backed; all existing callers (`TransactionIntakeService.php`, `ProcessTransactionIntakeJob.php`, `TransactionValidationService.php`) call only the facade methods, none reach around it — a store-interface split does not break any existing caller.
New API surface: `Metrics::sample($name, $value, array $dims = [])` / `Metrics::percentile($name, $p, array $dims = [])`, backed by a capped Redis structure trimmed atomically via a single-key Lua script on insert (Invariant 2).
Specify before writing code: sample count cap and/or time-window cap; percentile interpolation rule; empty-result representation (`null`, not `0`, so callers can distinguish "no data" from "zero latency"); minimum-sample-count/confidence metadata on a percentile read; dimension allowlist (no arbitrary caller-supplied dimension keys); key hashing/canonicalization for stable dimension-combination keys; unique/scored insertion so duplicate numeric samples are not collapsed; **cardinality budget** — a maximum number of distinct dimension-combination keys per metric name, a TTL on every such key, and defined behavior when the budget is exceeded (reject the new combination, or coalesce into a fixed `other` bucket — pick one and document it).
Fix the confirmed dead `ObservabilityController` bug as part of this work unit: `Metrics::get('intake.dispatch_latency:avg', ...)` reads a key nothing ever writes (`Metrics::timing()` writes the bare `metrics:{name}` key, `Metrics::bucket()` writes `metrics:{name}.last_bucket`) — the existing `latencies` block of the observability snapshot silently always returns 0 today.
Files: `app/Support/Metrics.php` (split into facade + store interface + two store implementations), `app/Http/Controllers/API/V1/ObservabilityController.php` (bug fix).

**WU3 — DB pressure instrumentation, reactive-only (T053)**
Instrument `DeadlockRetryService::withDeadlockRetry()` at the point it already detects deadlocks/lock-waits (via `str_contains()` matching on the exception message today): counters (`db.deadlock_retry.attempted/succeeded/exhausted/non_retryable`), timing/distribution via WU2's sampling API (`delay_ms`, `operation_ms`, `total_recovery_ms`).
**Exception-logging constraint (must-fix)**: Laravel's `QueryException::formatMessage()` interpolates the full SQL text and bindings directly into `$e->getMessage()`. **Do not log `$e->getMessage()` raw.** Record only sanitized database error metadata — `SQLSTATE`/driver error code extracted from `$e->errorInfo[0]`/`[1]` (confirmed available via `QueryException`'s copy of the underlying `PDOException`'s `errorInfo`) — plus the request's correlation ID (per WU1) and safe operational context (`operation`, `attempt`, `max_attempts`, `delay_ms`, `connection`). Never log SQL text, bindings, credentials, or tenant payload data.
Document in code (and later the runbook) that this measures *retry-observed* pressure, not total DB pressure — zero retries does not mean zero contention.
Files: `app/Services/DeadlockRetryService.php`.

**WU4 — Rejection, queue, breaker, and bounded skew metrics (T053 remainder)**
Add `Metrics::incr()` labeled by reason at every rejection path (payload-size, batch-size, fairness, backpressure, circuit breaker — confirmed none of the four ingestion middleware call `Metrics::` today, so this is genuinely new instrumentation, not a duplicate). Persist queue depth; for queue *age*, run a feasibility check first:
```
Confirm a stable timestamp source exists for ready, delayed, and reserved
Horizon/Redis jobs before promising "oldest pending job age" as a metric.

If no reliable source exists, expose one of these honestly instead:
- last observed enqueue-to-dispatch latency, or
- oldest known intake age from application records (transaction_intake table), or
- `available: false` with a stated reason.
Do not infer true queue age from a value that only reflects recently
processed jobs.
```
Mirror circuit breaker state transitions into a `Metrics` gauge. For tenant/terminal skew, use bounded ranking state, not unrestricted per-terminal counters:
```
- time-windowed Redis sorted set (explicit TTL on the whole structure)
- bounded top-N reads only
- explicit maximum ranking-member count per window (cap the sorted set
  size on insert, evicting the lowest-ranked member past the cap)
- no terminal/tenant dimension injected into every generic metric key
- no unbounded per-terminal historical counters
```
The same cardinality-budget requirement from WU2 applies here.
Files: `app/Http/Middleware/IngestionPayloadSizeMiddleware.php`, `app/Http/Middleware/IngestionBackpressureMiddleware.php`, `app/Http/Middleware/IngestionFairnessMiddleware.php`, `app/Http/Middleware/CircuitBreakerMiddleware.php`, `app/Services/IngestionBackpressureService.php`, `app/Services/CircuitBreaker.php`, `app/Services/IngestionFairnessService.php`.

**WU5 — TTL-bounded tenant fairness override, including its own configuration**
Redis-backed override store: modes `inherit` / `reduced_limit` / `blocked`, mandatory TTL (no permanent override by default), operator + reason + timestamps recorded, atomic set/clear via single-key Lua (Invariant 2), fail-open on any Redis failure or missing key (Invariant 1).
**Configuration ownership (must-fix, corrected)**: `config/tsms.php` is part of this work unit's file list. The maximum permitted override TTL is defined here, in the same commit that enforces it (Invariant 7) — not introduced later by WU8. WU8 may consume or document this setting; it must not own its initial definition or introduce a duplicate.
**Expiry/absence semantics (honest model)**: Redis TTL expiry does not execute application code, and a missing key alone cannot distinguish never-created, explicitly-cleared, or naturally-expired.
```
- log create, replace, and explicit clear immediately (real events)
- a missing or expired override is treated as `inherit`
- do not claim whether absence means never-created, explicitly-cleared,
  or TTL-expired — the override store itself cannot tell these apart
- record `override_missing` only when useful for diagnostics (i.e. a
  caller expected an override to be active and found none)
- expiry is inferred only when a retained audit record shows a prior
  override whose `expires_at` is now in the past — never inferred from
  key absence alone
- no scheduled sweeper or keyspace notifications for now (scope control)
```
Additional decisions fixed before implementation: `blocked` returns HTTP 429 (consistent with the existing fairness-limit rejection status) with `Retry-After` set to the override's remaining TTL; a hard cap on maximum TTL (the WU5-owned config value above); the tenant ID must correspond to a known tenant before an override can be set; `clear` is idempotent (clearing a non-existent override is a no-op success); the authorizing operator/CLI identity is recorded on every command invocation.
Checked by `IngestionFairnessService` before its existing global-limit decision (Fairness Architecture points 1–6 remain unchanged); `reduced_limit` replaces only that tenant's limit, `blocked` rejects immediately, all other tenants unaffected.
**Reconciliation with Fairness Architecture point 7 (must state explicitly)**: point 7 above deferred *tenant-tier overrides* — a persistent, config-driven tier/policy system — as out of scope for this feature, recommending "a config-file override map... not a new database table" if ever built. WU5 is a **different use case**: a temporary, TTL-bounded, incident-response control for actively throttling one tenant during a live drill or production incident, not a persistent tiering policy. WU5 does **not** introduce tenant-tier policy management and does **not** reopen point 7's binding decision — the two mechanisms coexist without conflict because WU5 has no tier concept, no persistent policy schema, and expires by design.
New Artisan commands: `ingestion:tenant-throttle:set --tenant= --mode= [--limit=] --ttl= --reason=`, `ingestion:tenant-throttle:status --tenant=`, `ingestion:tenant-throttle:clear --tenant=`.
Files: `config/tsms.php` (new max-TTL setting), new `app/Services/TenantFairnessOverrideService.php`, new `app/Console/Commands/TenantThrottleSet.php` / `TenantThrottleStatus.php` / `TenantThrottleClear.php`, `app/Services/IngestionFairnessService.php`.

**WU6 — Tests (not a standalone commit, T048)**
Written and committed alongside WU1–WU5, one test file/case per behavior introduced. Explicitly required: a fail-open test for WU5 (Redis unavailable → `inherit`, never `blocked`), a precedence test for WU1 (both headers present, different values → `X-Request-Id` wins + warning logged), and an empty-sample test for WU2's percentile reads.

**WU7 — Read-only observability API contracts (T054)**
**Authorization (must-fix, corrected)**: Sanctum's `abilities:admin:manage` ability, which already gates the existing `/api/v1/observability/*` routes (`routes/api.php:222-230`), is the authoritative authorization primitive for these endpoints unless a future explicit decision changes it. Do **not** rely on `TenantScope` or `BelongsToTenant` for isolation on the new Redis-backed ranking/metric structures — `TenantScope` is an opt-in-per-model Eloquent global scope (only `Transaction` uses it, not `TransactionIntake`), is skipped in console context, is bypassed for any `hasRole('admin')` user, and has no mechanism that applies to raw Redis sorted sets at all; it must not be assumed to provide isolation it cannot provide. **Introducing tenant-scoped (non-admin) access to any observability endpoint is a genuine broadening of the authorization surface and is out of scope for this work unit unless explicitly approved separately** — today no non-admin token can reach any observability endpoint, and this plan does not change that.
Endpoints: queue depth, queue age/oldest-pending (per WU4's feasibility outcome), circuit-breaker state, backpressure state, DB pressure indicators (WU3), tenant/terminal skew (WU4's bounded ranking), rejection reasons, percentile metrics (WU2). One common response envelope:
```json
{
  "generated_at": "...",
  "source": "redis|database|application",
  "window": "...",
  "freshness_seconds": 0,
  "status": "available|degraded|unavailable",
  "data": {}
}
```
Strictly read-only (Invariant 6); predictable `degraded`/`unavailable` responses when a backing store is unreachable rather than a 500; bounded top-N/filter parameters; response-size limits; no exposure of sensitive terminal/provider metadata beyond what's operationally needed. Targeted controller tests per endpoint, including one degraded-state test per endpoint.
Files: `app/Http/Controllers/API/V1/ObservabilityController.php`, new `docs/OBSERVABILITY_DASHBOARD.md` (confirmed no naming collision with existing docs).

**WU8 — Alert definitions and DB-pressure configuration (T055, honestly named)**
Deliverable is named "alert definitions and operational checks" — not "alerts implemented" — since no live evaluator or notification path is introduced. Reference existing config rather than inventing new numbers: `tsms.intake.backpressure.max_queue_depth`/`retry_after_seconds` (`config/tsms.php:81-82`), `tsms.circuit_breaker.failure_threshold`/`reset_timeout_seconds` (`config/tsms.php:103-104`), `tsms.fairness.{global,tenant,terminal}.limit` (`config/tsms.php:125-133`), Horizon's `waits` block (`config/horizon.php:44-57`). Add one new config entry for DB lock-wait/deadlock-retry alert thresholds (informed by WU3). WU8 consumes/documents WU5's max-TTL setting; it does not redefine it (Invariant 7, must-fix correction above).
Each documented alert/check specifies: signal, threshold, evaluation window, severity, recovery threshold, data-freshness requirement, false-positive caveat, operator response, ownership, and whether the check is manual or automated.
Files: `config/tsms.php` (new DB-pressure threshold entry only — not the WU5 TTL setting), new `docs/OBSERVABILITY_ALERT_DEFINITIONS.md`.

**WU9 — Runbooks (T056)**
Two new runbooks (failed-job replay documenting `ReconcileStrandedIntake`'s existing options; tenant throttling documenting WU5's commands) plus two extensions (general rollback runbook beyond the current shard-count-specific one; Horizon-scaling-under-DB-connection-limits per this file's Phase 6 note on reviewing DB connection limits before increasing worker capacity). Written last so cross-references to WU7/WU8 are accurate.
**Observe-mode outage risk (must document, corrected)**: the tenant-throttling runbook must state explicitly:
- The default backpressure mode is `observe` (`config/tsms.php:80`, `TSMS_INTAKE_BACKPRESSURE_MODE`).
- During a Redis failure while in `observe` mode, a tenant explicitly marked `blocked` via WU5's override can degrade to **unrestricted** admission, because both `IngestionFairnessService`'s base check and WU5's override check fail open, and backpressure provides no fail-closed backstop outside `enforce` mode (Fairness Architecture point 4's fail-closed backstop only applies when backpressure is in `enforce` mode).
- Detection signals: WU7's degraded-status observability endpoints, Redis connectivity alerts from WU8.
- Operator response: if a `blocked`/`reduced_limit` override is actively relied upon during an incident and Redis becomes unreliable, the operator must know that switching backpressure to `enforce` mode restores a fail-closed backstop for the whole ingestion path (not just the throttled tenant) — document this as an explicit, deliberate operational trade-off (broader rejection risk vs. losing the per-tenant throttle's protection), not an automatic action.
Files: `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` (extend), `docs/SHARD_COUNT_CHANGE_RUNBOOK.md` (extend), new `docs/FAILED_JOB_REPLAY_RUNBOOK.md`, new `docs/TENANT_THROTTLING_RUNBOOK.md`.

**WU10 — Final targeted regression and rollback checks (T057, T058, T060)**
Re-run the Gate 0 baseline (full `Unit`+`Feature` suites, the named targeted suites, and `scripts/verify-rollback-branch.sh`) after WU1–WU9 land, and diff against the Gate 0 baseline rather than treating a fresh pass as sufficient on its own. Document results in `docs/`.

**WU11 — Spec Kit delivery-status synchronization**
Update `tasks.md` and this file (`plan.md`) after WU1–WU10 are independently verified. Do **not** mark T049–T051, T059, or T061 complete. Target wording: "T048 and T052–T056 implemented and validated; staging drills T049–T051 and final readiness gates T059/T061 remain pending."

#### Verification

- Gate 0 baseline recorded before WU1 starts; WU10 diffs against it, not just re-runs tests fresh.
- Each delivery unit gated by targeted PHPUnit tests, including the fail-open/precedence/empty-sample tests called out in WU6.
- Laravel Pint (`--test` first, isolate pre-existing vs. new violations) before each commit.
- Any new Redis Lua script uses a single key (`KEYS[1]`) per script for cluster-safety (Invariant 2).
- Fail-open behavior explicitly tested for WU5's tenant override (Invariant 1).
- WU7 endpoints tested for both `available` and `degraded`/`unavailable` states, and confirmed to rely only on the Sanctum `abilities:admin:manage` gate (not `TenantScope`/`BelongsToTenant`) for authorization.
- Nine logical commits as listed under "Commit / Delivery-Unit Rule," each independently reviewable.

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
