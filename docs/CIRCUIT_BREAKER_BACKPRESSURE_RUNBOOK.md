# Circuit Breaker & Backpressure Runbook

## 1. Purpose and Scope

This runbook covers the two independent overload-protection mechanisms guarding official and batch transaction ingestion (`POST /api/v1/transactions/official`, `POST /api/v1/transactions/batch`):

- **Circuit breaker** (`App\Services\CircuitBreaker`, applied via `circuit.breaker:transaction-intake` middleware) protects against a **failing downstream dependency** — it tracks consecutive failures reaching the protected resource and stops sending traffic to it once it appears broken, giving it time to recover.
- **Backpressure** (`App\Services\IngestionBackpressureService`, applied via `ingestion.backpressure:processing` middleware and internally by `TransactionIntakeService::handleOfficialIntake()` for the intake queue) protects against **queue depth overload** — it rejects new work when a Redis-backed queue is already carrying more jobs than it can drain in a reasonable time, regardless of whether the downstream dependency itself is healthy.

These are answering different questions: "is the thing on the other end of this call broken?" (breaker) versus "is there already too much unprocessed work queued?" (backpressure). A request can be rejected by either, independently, for unrelated reasons. Both mechanisms are separate from **fairness** (`App\Services\IngestionFairnessService`, T044/T045), which is out of scope for this runbook.

This document reflects the final, shipped implementation as of T028/T028a/T028b/T035 (see "Change History," §11). It does not describe an in-progress or planned design.

## 2. Authoritative Production Surfaces

These are the **only** sources of truth for live ingestion breaker/backpressure state:

- **Circuit breaker service and Redis key**: `App\Services\CircuitBreaker`, constructed with `serviceKey = 'transaction-intake'` (`CircuitBreaker::INGESTION_SERVICE_KEY`) by `circuit.breaker:transaction-intake` middleware (`app/Http/Middleware/CircuitBreakerMiddleware.php`, registered on both ingestion routes in `routes/api.php`). State lives in a single Redis hash at:
  ```
  {tsms.circuit_breaker.key_prefix}{serviceKey}
  ```
  which, with default config, is `tsms:circuit-breaker:transaction-intake`, under the Redis connection named by `tsms.circuit_breaker.redis_connection` (default `default`).
- **Processing-queue backpressure path**: `ingestion.backpressure:processing` middleware (`app/Http/Middleware/IngestionBackpressureMiddleware.php`), calling `IngestionBackpressureService::checkProcessing()`, which inspects the tenant's processing queue as resolved by `IngestionQueueRouter::processingQueueForTenant()` (queue names of the form `transaction-processing:s{N}`).
- **Official-intake backpressure path**: `TransactionIntakeService::handleOfficialIntake()`'s own `IngestionBackpressureService::checkAggregate()` call, which evaluates **both** the intake queue (`IngestionQueueRouter::intakeQueueForTenant()`, queue names `transaction-intake:s{N}` or `transaction-intake:s-{vip}`) and the processing queue together, and rejects/degrades through `TransactionController::storeOfficial()`'s response construction if either is overloaded — independently of whether the route-level `ingestion.backpressure:processing` middleware already passed.

## 3. Legacy or Misleading Surfaces — Do Not Use

Three surfaces exist in this codebase that look like circuit-breaker status but do **not** reflect real ingestion breaker state. Using any of them to diagnose an ingestion incident will show you stale or entirely unrelated data:

1. **`App\Http\Controllers\API\V1\CircuitBreakerController`** — queries `App\Models\CircuitBreaker`, an unrelated legacy Eloquent/DB-backed model with its own `tenant_id`/`name`/`status` schema. Has no relationship to `App\Services\CircuitBreaker`'s Redis state.
2. **`App\Http\Controllers\API\Dashboard\CircuitBreakerController`** — same legacy `App\Models\CircuitBreaker` model, via a different query shape (`service_name`, `state`, `cooldown_until`).
3. **`App\Http\Controllers\CircuitBreakerController`** (the `dashboard.circuit-breakers` Blade view) — reads from `storage_path('framework/circuit-breakers/{service}_state.txt')` and sibling files. `App\Services\CircuitBreaker` no longer writes to the filesystem at all (it is Redis-only), so this dashboard is stale/non-functional for the real breaker. This gap is already documented in `specs/001-100-tenant-resilience/contracts/operational-signals.md`.

**Rule: never treat (1), (2), or (3) as evidence of live ingestion breaker state, in either direction** — neither "these say the breaker is closed, so we're fine" nor "these say it's open, so escalate" is valid. Only §2's authoritative surfaces are trustworthy.

## 4. Circuit Breaker States

States: `closed` → `open` → `half-open` → (`closed` or back to `open`).

- **CLOSED**: normal operation. Every request passes through; `recordFailure()`/`recordSuccess()` accumulate `failure_count`. Once `failure_count >= failure_threshold` (`tsms.circuit_breaker.failure_threshold`, default 5), the breaker transitions to `open` (logged as `CircuitBreaker: opened`).
- **OPEN**: requests are rejected immediately (`isAvailable()` returns `false`) until `reset_timeout_seconds` (default 60) has elapsed since `opened_at`.
- **HALF_OPEN**: once the reset timeout elapses, the breaker allows a bounded number of "probe" requests through to test whether the dependency has recovered, per the design in `specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md`:
  - **Maximum 3 concurrent probes** (`HALF_OPEN_MAX_PROBES`) admitted per half-open "generation." A 4th+ concurrent admission attempt is rejected and self-corrects the probe counter back down — it does not consume a slot.
  - **Closes on 2 successes** (`HALF_OPEN_CLOSE_THRESHOLD`) of the up to 3 probes — `reset()` is invoked, `state` returns to `closed`, `failure_count` is zeroed.
  - **Reopens on 2 failures** (`HALF_OPEN_REOPEN_THRESHOLD`) of the up to 3 probes — `state` returns to `open`, `opened_at` is bumped.
  - **Generation handling**: every time the breaker transitions from `open` to `half-open`, `half_open_generation` is incremented by exactly 1. A probe's admitted generation is fixed at admission time and threaded through the request (via `CircuitBreakerMiddleware`'s `circuit_breaker.half_open_generation` request attribute) to whichever `recordSuccess()`/`recordFailure()` call eventually reports its outcome.
  - **Stale/late outcome behavior**: `recordSuccess($generation)`/`recordFailure($generation)`, when given a non-null generation, re-check the breaker's *current* `state` and `half_open_generation` before mutating anything. If either no longer matches what the probe was admitted under (the circuit already closed, already reopened, or moved on to a newer generation), the outcome is discarded with **zero mutation** — a late failure cannot reopen an already-closed circuit, and a late success cannot close an already-reopened one.
  - **Expiry and recovery**: if a half-open episode does not resolve (doesn't reach 2 successes or 2 failures) within `reset_timeout_seconds` of entering half-open, the next request to observe this drives the breaker back to `open` and lets it re-enter `half-open` fresh on a **new** generation once the timeout elapses again — an expired episode's counters are never reused in place.
  - **Atomicity**: admission, outcome recording, and the `open`→`half-open` transition itself are each implemented as a single atomic Redis Lua script (`HALF_OPEN_ADMISSION_SCRIPT`, `HALF_OPEN_OUTCOME_SCRIPT`, `HALF_OPEN_TRANSITION_SCRIPT` in `app/Services/CircuitBreaker.php`) — see the ADR for why a third script was required to close a transition race found during review.

## 5. Backpressure Behavior

- **Queue-depth thresholds**: a queue is `overloaded` when its ready-job depth (`LLEN queues:{queueName}`) reaches or exceeds `tsms.intake.backpressure.max_queue_depth` (default 5000).
- **Enforce vs. observe modes** (`tsms.intake.backpressure.mode`, default `observe`): in `observe` mode, an overloaded queue is logged (`Ingestion backpressure threshold reached`) but never rejects a request (`enforced` stays `false`). In `enforce` mode, an overloaded queue actually rejects/degrades the request.
- **Failure mode**: if the Redis depth check itself throws (e.g. Redis unavailable), `IngestionBackpressureService::checkQueue()` fails **closed only when `mode === 'enforce'`** (`$failClosed = $this->enforced()`) — i.e. a Redis outage in enforce mode is treated as `degraded`+`enforced`, which rejects traffic rather than letting it through unchecked. In `observe` mode, the same Redis failure does not enforce a rejection.
- **Processing rejection**: `ingestion.backpressure:processing` middleware rejects/degrades before the request reaches `TransactionController` at all.
- **Official-intake rejection**: `TransactionIntakeService::handleOfficialIntake()`'s own `checkAggregate()` call independently evaluates the intake queue (in addition to processing), so a request can be rejected here even if the processing-queue middleware already passed — this is the path T035 fixed (see below).
- **Retry-After semantics and header/body consistency (T028b, T035)**: every rejection/degraded response in both mechanisms computes its retry hint **once** and applies the identical value to both the JSON body and the `Retry-After` HTTP header:
  - Circuit breaker: `CircuitBreaker::retryAfterSeconds()`, applied in `CircuitBreakerMiddleware::handle()`.
  - Backpressure (processing path): `IngestionBackpressureService::retryAfterSeconds()` (private, via `degradedPayload()`/`rejectionPayload()`), applied in `IngestionBackpressureMiddleware::handle()`.
  - Backpressure (official-intake path): the same `retry_after_seconds` value present in `TransactionIntakeService::handleOfficialIntake()`'s result array is applied by `TransactionController::storeOfficial()` — this specific header attachment was the T035 fix; before it, the JSON body carried `retry_after_seconds` but no `Retry-After` header was sent for this path.

## 6. Redis Keys and Fields

**Circuit breaker** — single hash `tsms:circuit-breaker:transaction-intake` (TTL `tsms.circuit_breaker.state_ttl_seconds`, default 3600s, refreshed on every write):

| Field | Meaning | Safe to inspect? |
|---|---|---|
| `state` | `closed` \| `open` \| `half-open` | Yes |
| `failure_count` | Closed-state consecutive-failure counter; reset to 0 on entering half-open | Yes |
| `opened_at` | Unix timestamp when `open` began | Yes |
| `half_open_generation` | Monotonic counter, bumped each `open`→`half-open` transition | Yes |
| `half_open_started_at` | Unix timestamp the current half-open episode began | Yes |
| `half_open_probe_count` | Probes admitted so far in the current generation (capped at 3) | Yes |
| `half_open_successes` / `half_open_failures` | Outcome tally for the current generation | Yes |
| `last_success_at` / `last_failure_at` | Unix timestamps | Yes |

All fields are safe to `HGETALL`/`HGET` — read-only inspection never mutates state.

**Backpressure** — no dedicated hash; depth is read live from the standard Laravel Redis queue lists (`LLEN queues:{queueName}`) for whichever queue name `IngestionQueueRouter` resolves for a given tenant. There is no separate backpressure-specific Redis key to inspect beyond the queue lists themselves.

## 7. Operational Diagnosis

**Symptoms**: elevated 503s from `/transactions/official`/`/transactions/batch` (circuit breaker or backpressure-degraded), elevated 429s (backpressure rejection), or client reports of "Circuit is open due to multiple failures" / `INGESTION_BACKPRESSURE` / `INGESTION_DEGRADED` error codes/messages.

**Logs to check** (all via standard Laravel log channel):
- `CircuitBreaker: opened` (warning) — breaker just tripped.
- `CircuitBreaker: transitioned to half-open` (info) — recovery attempt starting.
- `CircuitBreaker: half-open episode expired without resolution, reopening` (warning) — probes were abandoned/inconclusive.
- `CircuitBreaker: closed after half-open probe successes` (info) — recovered.
- `CircuitBreaker: reopened after half-open probe failures` (warning) — recovery attempt failed.
- `CircuitBreaker: failed to read state, failing open` / `failed to record success` / `failed to record failure` / `failed to reset` (error) — Redis itself is unreachable from the breaker's perspective.
- `CircuitBreakerMiddleware: exception before downstream attempt, not recorded against breaker` (error) — an exception occurred before the protected resource was even reached (e.g. validation failure); correctly excluded from breaker accounting.
- `Ingestion backpressure threshold reached` (warning) — a queue crossed `max_queue_depth`.
- `Failed to evaluate ingestion backpressure` (error) — the Redis depth check itself failed.

**Safe read-only commands**:
```
redis-cli HGETALL tsms:circuit-breaker:transaction-intake
redis-cli LLEN queues:transaction-processing:s0        # repeat per shard index
redis-cli LLEN queues:transaction-intake:s0            # repeat per shard index
php artisan tsms:shard-topology-verify                 # read-only; see docs/SHARD_COUNT_CHANGE_RUNBOOK.md
php artisan horizon:status
php artisan horizon:supervisors
```

**Distinguishing dependency failure from queue overload**:
- If `tsms:circuit-breaker:transaction-intake`'s `state` is `open` or `half-open`, and `failure_count`/`half_open_failures` is climbing, the protected dependency itself is failing (5xx from downstream, exceptions) — this is a **breaker** condition.
- If the breaker's `state` is `closed` but requests are still being rejected with `INGESTION_BACKPRESSURE`/`INGESTION_DEGRADED`, and `LLEN` on the relevant queue is high or climbing, this is a **backpressure** condition — the dependency itself may be healthy, but the queue is backed up (e.g. Horizon workers can't keep pace with intake volume).
- Both can be true simultaneously; check both independently rather than assuming one implies or excludes the other.

## 8. Recovery Procedures

- **Breaker OPEN**: identify and fix the underlying downstream failure (check `last_failure_at` and recent `CircuitBreaker: opened`-adjacent error logs for the actual failure cause). No manual Redis intervention is normally required — the breaker will attempt half-open recovery automatically once `reset_timeout_seconds` elapses. Do not manually force `state` to `closed`; this bypasses the probe verification the breaker exists to provide (see §9).
- **Breaker stuck HALF_OPEN**: check `half_open_started_at` against the current time — if the elapsed time exceeds `reset_timeout_seconds`, the *next* request will correctly trigger expiry-driven reopening automatically. No manual action needed; this is self-healing by design. If probes are being admitted but consistently failing (`half_open_failures` incrementing), this indicates the dependency has not actually recovered — treat as a breaker-OPEN diagnosis above.
- **Processing queue overload**: check Horizon worker health for the affected `transaction-processing:s{N}` supervisor (production: `high-supervisor`; staging: `processing-supervisor` — see `config/horizon.php`) via `php artisan horizon:status`/`horizon:supervisors`. Confirm workers are actually running and not stalled. If genuinely under-provisioned for load, this is a capacity/scaling decision, not a runbook action — escalate rather than manually manipulating queue contents.
- **Intake queue overload**: same diagnosis approach against the `transaction-intake:s{N}` queues, consumed by `intake-supervisor` in both environments.
- **Redis unavailable**: the breaker fails open (traffic passes through unchecked at the breaker level) while backpressure's own fail-closed-in-enforce-mode behavior becomes the active protection layer (see §5). Restoring Redis availability restores both mechanisms automatically — no manual reset is needed once Redis is back, since both services re-derive state from Redis on the next read.
- **Escalation criteria**: escalate to a human decision-maker (not an automated runbook action) if — the breaker cycles open→half-open→open repeatedly without ever closing (indicates the dependency is not recovering); queue depth continues climbing despite healthy-looking Horizon workers (likely a genuine capacity shortfall); or Redis itself is down for longer than a few minutes (both mechanisms' safety nets are time-limited, not indefinite).

## 9. Unsafe Actions

- **Do not manually delete** `tsms:circuit-breaker:transaction-intake` (or any queue key) without prior approval — deleting the breaker's hash resets it to an undefined/absent state that the next `isAvailable()` call will treat as `closed` (no probing), which defeats the half-open verification step entirely and can immediately re-expose a still-failing dependency to full traffic.
- **Do not manually reset counters** (`failure_count`, `half_open_successes`/`failures`, etc.) via `HSET`/`HDEL` — this is exactly the "blindly force closed" action the ADR's probe design exists to prevent; let the breaker's own state machine resolve recovery.
- **Do not confuse the legacy dashboards** (§3) with live state, in either direction.
- **Do not bypass backpressure** (e.g. by disabling `tsms.intake.backpressure.enabled` or switching `mode` to `observe`) while an overload condition is genuinely unresolved — this removes the only protection against the queue growing further and does not address the underlying cause.

## 10. Validation

Commands and expected healthy outcomes, all read-only against fakes (no live Redis/Horizon required):

```
php artisan test tests/Unit/RedisCircuitBreakerTest.php       # expect: all pass — real App\Services\CircuitBreaker state machine
php artisan test tests/Unit/Services/CircuitBreakerTest.php   # expect: all pass — same class, additional coverage
php artisan test tests/Feature/IngestionCircuitBreakerTest.php # expect: all pass — HTTP-level breaker behavior
php artisan test tests/Feature/IngestionBackpressureTest.php  # expect: all pass — processing + official-intake backpressure, including T035's Retry-After header assertion
php artisan test tests/Feature/IngestionBackpressureFailureModeTest.php # expect: all pass — Redis-failure fail-closed-in-enforce behavior
```

Note: `tests/Unit/CircuitBreakerTest.php` (no `Services` subdirectory) tests the unrelated legacy `App\Models\CircuitBreaker` — passing or failing there has no bearing on real ingestion breaker health; do not use it to validate this runbook's subject matter.

A healthy production/staging state, read live:
```
redis-cli HGETALL tsms:circuit-breaker:transaction-intake
# expect: state = closed (or absent/no key at all, which is equivalent to closed)
```

## 11. Change History

- **T028a**: design decision (bounded concurrent probes, N=3, close on 2-of-3, reopen on 2-of-3, generation-based staleness) — `specs/001-100-tenant-resilience/adr/T028a-half-open-circuit-breaker-semantics.md`.
- **T028**: implementation and tests for the above, including the later-added `HALF_OPEN_TRANSITION_SCRIPT` fix for a transition race found during review.
- **T028b**: `Retry-After` header on circuit-breaker `OPEN`/over-cap `HALF_OPEN` rejections.
- **T035**: `Retry-After` header on the official-intake-overload rejection path (`TransactionController::storeOfficial()`), closing the last header/body inconsistency gap.
- This runbook documents the **final, shipped state** of all of the above — it was deliberately written last (after T028/T028a/T028b/T035 landed) specifically so it would not need a rewrite mid-implementation.

## Unknown / Not Verifiable From This Repository

- Actual production alerting thresholds/dashboards (Grafana, PagerDuty, etc.) tied to these logs/metrics are not present in this repository and cannot be documented here — **unknown**.
- Real-world time-to-recovery for a genuinely failing downstream dependency (as opposed to the configured `reset_timeout_seconds`) depends on the dependency itself and is **unknown**.
