# Data Model: 100 Tenant Ingestion Resilience

## Ingestion Request

Represents a durable official ingestion attempt before downstream transaction processing.

**Fields**:

- `id`: Internal primary key.
- `submission_uuid`: Provider submission identity.
- `tenant_id`: Tenant resolved from authenticated terminal or payload context.
- `terminal_id`: POS terminal identity.
- `payload_checksum`: Hash/checksum used for idempotency conflict detection.
- `payload`: Raw submitted payload.
- `payload_size_bytes`: Request byte size.
- `source_ip`: Source IP captured for intake auditing.
- `intake_status`: Received, rejected, accepted, or queued.
- `processing_status`: Processing, processed, duplicate, failed retryable, failed permanent, or dead lettered.
- `attempt_count`: Processing attempt count.
- `last_error_code`: Machine-readable processing or rejection error.
- `last_error_message`: Human-readable processing or rejection detail.
- `duplicate_of_intake_id`: Original intake reference for duplicates.
- `trace_id`: Trace identifier stored on the current `transaction_intake` table.
- `received_at`, `queued_at`, `processed_at`: Lifecycle timestamps.
- `created_at`, `updated_at`: Standard timestamps.

**Current schema note**: This entity maps to the existing `transaction_intake` table and `TransactionIntake` model. Do not create a parallel ingestion request table/model unless a later architecture decision explicitly replaces `transaction_intake`.

**Future-schema candidates**: `source` and `accepted_at` may be added later if official, batch, replay, and reconciliation attempts need first-class source attribution or accepted timing separate from received/queued timing.

**Header divergence to resolve**: `AttachCorrelationId` uses `X-Request-Id` and stores request attribute `correlation_id`, while `TransactionIntakeService` currently reads `X-Correlation-ID` for `trace_id`. The implementation must choose one canonical inbound header and propagation path before changing persisted trace semantics.

**Relationships**:

- Belongs to tenant.
- Belongs to terminal.
- May produce one or more transactions.
- May have many processing attempts or failure events.

**Validation Rules**:

- `submission_uuid` is required and unique for idempotent official intake.
- Same `submission_uuid` and same `payload_checksum` returns existing outcome.
- Same `submission_uuid` and different `payload_checksum` returns conflict.
- `payload_size_bytes` must not exceed configured limit.
- Submitted transaction count must not exceed configured limit before intake persistence.

## Backpressure Decision

Represents the system's accept/reject/degraded decision for an ingestion request.

**Fields**:

- `decision`: accept, observe, reject, degraded.
- `reason`: queue_backpressure, db_pressure, redis_unavailable, worker_lag, tenant_rate_limited, terminal_rate_limited, payload_limit, batch_limit.
- `retry_after_seconds`: Clamped positive retry value.
- `queue`: Queue evaluated.
- `shard`: Shard evaluated.
- `queue_depth`: Current queue depth when available.
- `oldest_job_age_seconds`: Queue age/drain signal when available.
- `db_health`: Healthy, degraded, unavailable, unknown.
- `redis_health`: Healthy, degraded, unavailable, unknown.
- `mode`: disabled, observe, enforce.

**Relationships**:

- Associated with an ingestion request or rejected request attempt.
- Feeds metrics, logs, and dashboards.

## Circuit Breaker State

Shared Redis state for protected ingestion dependencies.

**Fields**:

- `name`: Breaker identifier.
- `state`: closed, open, half_open.
- `consecutive_failures`: Count. Implemented as `failure_count` in `app/Services/CircuitBreaker.php`; reset to `0` on entry to half-open (see below).
- `rolling_failure_rate`: Recent failure percentage.
- `opened_at`: Timestamp.
- `half_open_generation`: Monotonically incremented integer, bumped every time `transitionToHalfOpen()` runs. Decided by T028a — see `specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md`. Used to detect and discard late/superseded probe outcomes: a probe's generation is captured at admission (`isAvailable()`) and carried forward to `recordSuccess()`/`recordFailure()`; if the breaker's current `state`/`half_open_generation` no longer matches what the probe was admitted under, the outcome is ignored (no counter mutation, no transition).
- `half_open_started_at`: Timestamp set when `transitionToHalfOpen()` runs for the current generation. Used to detect an expired half-open episode (no resolution within `resetTimeoutSeconds`) — an expired episode is resolved by starting a **new** generation (reopen, then re-enter half-open on the next elapsed-timeout observation), not by resetting the current generation's counters in place.
- `half_open_probe_count`: Number of probes **admitted** into the current half-open generation, capped at N=3 concurrent probes per generation (decided by T028a). This is an admission gate, not a live in-flight count — it is not decremented when a probe completes or is abandoned.
- `half_open_successes`: Count of successful outcomes recorded against the current half-open generation. The circuit closes once this reaches 2 (of up to 3 probes).
- `half_open_failures`: Count of failed outcomes recorded against the current half-open generation. The circuit reopens once this reaches 2 (of up to 3 probes).
- `last_success_at`: Timestamp.
- `last_failure_at`: Timestamp.
- `expires_at`: Optional TTL. Implemented as the existing whole-hash `expire()`-on-every-write (`stateTtlSeconds`); this remains a coarse orphaned-key safety net, not the mechanism that bounds a half-open episode's lifetime — `half_open_generation`/`half_open_started_at` staleness handles that.

**Rules**:

- Infrastructure failures count toward breaker state.
- Client validation failures do not count.
- State is shared across app instances.

**Half-open semantics (decided by T028a)**:

T028a's ADR (`specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md`) is the source of truth for full half-open behavior: bounded concurrent probes per circuit key (per `serviceKey`), N=3 max concurrent probes per generation, close on 2-of-3 successes, reopen on 2-of-3 failures, generation-scoped late-result discarding, no bookkeeping for abandoned probes (they simply never call `recordSuccess()`/`recordFailure()`), and expired episodes starting a fresh generation rather than an in-place reset. `recordSuccess()`/`recordFailure()` gain an optional `?int $generation = null` parameter; `null` preserves existing closed-state call sites with no generation check. This document's field list above reflects that decision; do not re-derive the semantics here — treat the ADR as authoritative and this table as a summary of its Redis field shape.

**Invariants (must hold under the approved T028a design)**:

- The breaker must not stay permanently open once the protected dependency has recovered — some path back to `closed` must exist and must be reachable without manual intervention.
- The breaker must not flood a still-recovering dependency — half-open admits at most N=3 concurrent probes per generation, strictly less than full unthrottled traffic.
- State transitions (`closed` → `open`, `open` → `half-open`, `half-open` → `closed`, `half-open` → `open`) must be observable/loggable, consistent with the existing `Log::warning('CircuitBreaker: opened', ...)` pattern in `recordFailure()`.
- The solution must work correctly under concurrent workers reading/writing shared Redis state — no lost updates (e.g. two workers both reading "0 probes admitted" and both proceeding as if admitting the 3rd probe). Any counter-based approach must use an atomic Redis primitive (e.g. `HINCRBY`, `INCR`, or a Lua script/`MULTI`/`EXEC` transaction), consistent with the existing `recordFailure()`/`reset()` pattern of using genuine `MULTI`/`EXEC` transactions rather than pipelining.
- `failure_count` semantics on transition to/from half-open are unambiguous: it is reset to `0` on entry to half-open and plays no role in the half-open close/reopen decision, which is governed solely by `half_open_successes`/`half_open_failures`.
- **Generation-safety invariant**: a stale or superseded outcome (recorded against a `state`/`half_open_generation` combination that no longer matches current breaker state) must never mutate `half_open_successes`, `half_open_failures`, `failure_count`, or `state` — late results are discarded, not applied.

## Fairness Bucket

Redis-backed fixed-window rate budget. Approved architecture (see plan.md's "Fairness Architecture" subsection for full rationale): a fixed-window counter via `INCR`+`EXPIRE` per scope — not a token-bucket, not a new Lua script — since a rate window is a single atomic increment, not a multi-step state race.

**Fields**:

- `scope`: global, tenant, terminal. Fairness is enforced per-tenant AND per-terminal, never per-shard — at the real configured shard count (`config('tsms.processing.shard_count')`, default 8) and spec.md's ~100-tenant assumption, roughly 12-13 tenants collide per shard, so shard-level limiting alone would still let one hot tenant starve its shard-mates.
- `scope_id`: Tenant ID or terminal ID when scoped, resolved via the authoritative tenant/terminal resolution order documented in plan.md's "Fairness Architecture" subsection (primary: authenticated `PosTerminal`'s `tenant_id`/`id`; fallback: request input; skip the check entirely if still unresolved).
- `window_key`: Redis key identifying the current fixed window for this scope (e.g. `tsms:fairness:{scope}:{scope_id}:{window_start}`).
- `limit`: Configured allowance per window. No tenant-tier differentiation — tenant-tier overrides are explicitly deferred/out of scope for this feature (see plan.md's "Fairness Architecture" subsection, point 7); a single global/tenant/terminal limit set applies to every tenant.
- `count`: Current `INCR` value within the active window.
- `window_seconds`: Fixed window duration.
- `reset_at`: Timestamp the active window resets; `Retry-After` on a fairness rejection is derived from this value, mirroring the pattern already established by backpressure (T035) and the circuit breaker (T028b).
- `mode`: observe, enforce.

**Rules**:

- Fairness checks run before enqueue, after payload-size, backpressure, and circuit-breaker checks (fairness runs last in the middleware order — cheapest/most-global checks first, most granular/expensive per-tenant+terminal check last).
- Rejections include stable reason and retry metadata (`retry_after_seconds` and a matching `Retry-After` header, same value).
- Fairness fails OPEN when its Redis operations fail — this deliberately diverges from `IngestionBackpressureService`'s fail-closed-in-enforce-mode convention, because backpressure's aggregate check is already the fail-closed backstop for the ingestion path; fairness is a peer/refinement above it, not a second backstop.

## Queue Route

Deterministic mapping from tenant to queue.

**Fields**:

- `tenant_id`: Tenant identifier.
- `queue_type`: intake or processing.
- `queue_name`: Selected queue.
- `shard_count`: Configured shard count.
- `algorithm`: Current routing algorithm.

**Rules**:

- All dispatch paths use shared router.
- Changing shard count may remap tenants and requires rollout documentation.

## Operational Signal

Metric/log/alert event used for load-test and release gates.

**Fields**:

- `name`: Metric or event name.
- `tenant_id`: Optional tenant context.
- `terminal_id`: Optional terminal context.
- `route`: API route.
- `queue`: Queue name.
- `shard`: Shard.
- `value`: Numeric or state value.
- `reason`: Rejection/failure reason.
- `correlation_id`: Trace identifier.
- `recorded_at`: Timestamp.
