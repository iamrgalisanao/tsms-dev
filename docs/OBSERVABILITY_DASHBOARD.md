# Observability Dashboard: Ingestion Endpoints

Reference for the eight read-only ingestion observability endpoints added
under `/api/v1/observability/ingestion/*` (WU7, T054,
`specs/001-100-tenant-resilience/plan.md`). These expose capabilities built
in earlier work units (backpressure, circuit breaker, DB-pressure
instrumentation, tenant/terminal skew ranking, rejection counters, percentile
distributions) that existed in Redis/Cache but had no HTTP-reachable surface
until now.

This document describes what each endpoint shows and how to use it during a
drill or incident. It does not define alert thresholds (WU8,
`docs/OBSERVABILITY_ALERT_DEFINITIONS.md`) or general incident procedures
beyond what is specific to reading these endpoints — for breaker/backpressure
diagnosis and recovery steps, see
`docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`; for shard-count topology, see
`docs/SHARD_COUNT_CHANGE_RUNBOOK.md`.

## 1. Authorization

Every endpoint below sits behind the exact same gate as the six pre-existing
`/api/v1/observability/intake*` endpoints: Sanctum's `abilities:admin:manage`
ability (`routes/api.php`, inside the
`Route::middleware('abilities:admin:manage')->group(...)` block, itself
nested inside the `/api/v1` group's `auth:sanctum` middleware). A caller
needs a valid Sanctum token whose abilities include `admin:manage` (or `*`).
There is no tenant-scoped or reduced-privilege variant of any of these
endpoints — all are global-admin-only, matching every other endpoint in
`ObservabilityController`. See that controller's file-level doc-comment for
the full authorization matrix.

A request without a valid token receives `401`; a request with a valid token
lacking `admin:manage` receives `403`. Every route is registered GET-only —
`POST`/`PUT`/`DELETE` to any of these paths returns `405`.

## 2. Response Envelope

Every endpoint below returns exactly this shape:

```json
{
  "generated_at": "2026-08-09T12:00:00+00:00",
  "source": "redis|database|application",
  "window": "...",
  "freshness_seconds": 0,
  "status": "available|degraded|unavailable",
  "data": {}
}
```

- `generated_at`: ISO-8601 timestamp of when this response was built.
- `source`: which backing store the data primarily came from. `redis` for
  endpoints reading Redis structures directly (queue depth/age, circuit
  breaker, skew ranking, percentiles); `application` for endpoints reading
  through the `Metrics` facade's Cache-backed counter API (rejection
  reasons, DB-pressure counters — DB pressure blends Cache-backed counters
  with Redis-backed percentiles under this facade, so it is reported as
  `application` rather than picking one arbitrarily).
- `window`: a short label for what time range/freshness the data represents
  (`instantaneous` for live Redis reads, `current_window` for the
  time-windowed skew ranking, `cumulative_since_last_reset` for counters
  that only ever increase, `recent_samples` for percentile reads over the
  bounded recent-sample set).
- `freshness_seconds`: always `0` for every endpoint here — each read is a
  live, synchronous pull performed at request time, never a cached or
  precomputed snapshot. (Reserved for future use if any endpoint is ever
  backed by a cached/precomputed value instead.)
- `status`:
  - `available`: the backing read succeeded and produced real data,
    including a legitimate zero/empty result (e.g. no rejections have
    happened yet, or no `db.deadlock_retry.*` samples exist yet).
  - `degraded`: the backing store answered for **some but not all** of what
    the endpoint checked (currently only meaningful for the two per-shard
    endpoints — queue depth and queue age — when some shards are readable
    and others throw).
  - `unavailable`: the backing read failed outright (e.g. a Redis
    exception, or a Cache-store exception). The HTTP status code is still
    `200` — a backing-store outage is reported honestly in the envelope,
    never surfaced as an uncaught exception or a generic 500.
- `data`: endpoint-specific payload, described per endpoint below.

**A known, deliberate limitation**: three endpoints (tenant/terminal skew,
rejection reasons via their underlying counters, percentile metrics) read
through services/stores (`SkewRankingService`, `Metrics::percentile()`) that
already swallow their own Redis failures internally and return an empty/null
result rather than propagating an exception — this is by design in those
services (they must never affect ingestion admission or behavior). Because
of this, a genuine backing-store outage on those specific reads is
indistinguishable from "nothing has happened yet" and will show `available`
with empty/null data rather than `unavailable`. This is documented on each
affected endpoint below and inline in `ObservabilityController`, not an
oversight.

## 3. Endpoints

### 3.1 `GET /api/v1/observability/ingestion/queue-depth`

Per-shard ready-job depth (`LLEN queues:{name}`) for every intake and
processing shard queue, via `IngestionBackpressureService::currentDepth()`
(new in this work unit — a pure LLEN read with none of `checkQueue()`'s
admission-decision side effects: no metrics write, no log line, no rejection
counter increment).

```json
"data": {
  "intake": { "shards": [{ "queue": "transaction-intake:s0", "available": true, "depth": 12 }] },
  "processing": { "shards": [{ "queue": "transaction-processing:s0", "available": true, "depth": 5 }] },
  "threshold": 5000,
  "mode": "observe"
}
```

**Drill/incident use**: first stop when investigating elevated 429/503s from
ingestion — compare `depth` per shard against `threshold` to see which
specific shard(s) are overloaded, and `mode` to know whether backpressure is
actually enforcing rejections (`enforce`) or only logging (`observe`).

### 3.2 `GET /api/v1/observability/ingestion/queue-age`

Per-shard "oldest ready job" age via
`IngestionBackpressureService::oldestReadyJobAge()` (WU4). Ready-queue-head
age only (not delayed/reserved) — see that method's doc-comment for the
feasibility investigation behind this scope choice.

```json
"data": {
  "intake": { "shards": [{ "queue": "transaction-intake:s0", "available": true, "age_seconds": 3.2, "source": "ready_queue_head_pushed_at", "reason": null }] },
  "processing": { "shards": [...] }
}
```

`available: false` with a `reason` (`pushed_at_unavailable` or
`redis_unavailable`) means age could not be honestly determined for that
shard — never inferred or guessed.

**Drill/incident use**: pair with queue depth — a high depth with a low age
suggests a recent burst still draining normally; a high depth **and** a high
age suggests workers have stalled or fallen behind.

### 3.3 `GET /api/v1/observability/ingestion/circuit-breaker`

State of the shared ingestion circuit breaker
(`CircuitBreaker::INGESTION_SERVICE_KEY`, `'transaction-intake'`). Exposes
**both**:

- `live`: the real Redis-backed state via `CircuitBreaker::currentState()`
  (new in this work unit) — a pure `HGETALL`. This deliberately does **not**
  call `isAvailable()`, which has real side effects (it can admit/consume a
  half-open probe slot or drive an `open`→`half-open` transition as a side
  effect of merely being checked) — calling it from a read-only endpoint
  would violate the read-only contract.
- `metrics_mirrored`: the WU4 `Metrics`-gauge mirror
  (`circuit_breaker.state.{serviceKey}`), for lightweight dashboard polling.

```json
"data": {
  "service": "transaction-intake",
  "live": { "state": "closed", "failure_count": 0, "opened_at": 0, "half_open_generation": 0, "half_open_started_at": 0, "half_open_probe_count": 0, "half_open_successes": 0, "half_open_failures": 0 },
  "metrics_mirrored": { "state_code": 0, "state_label": "closed" }
}
```

**Drill/incident use**: this is the same `HGETALL` data
`docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §6/§7 already documents as
safe to inspect via `redis-cli` — this endpoint is that same data over HTTP,
for dashboards that can't shell into Redis directly. Cross-reference that
runbook for full state-machine semantics and recovery procedures.

### 3.4 `GET /api/v1/observability/ingestion/backpressure`

Current backpressure enforcement configuration plus a depth summary (reuses
the same per-shard depth read as §3.1, so the two endpoints never disagree).

```json
"data": {
  "enabled": true,
  "mode": "enforce",
  "threshold": 5000,
  "retry_after_seconds": 60,
  "intake_max_depth": 12,
  "processing_max_depth": 340
}
```

**Drill/incident use**: quick single-call check of whether backpressure is
even armed (`enabled`/`mode`) before digging into per-shard detail via
§3.1.

### 3.5 `GET /api/v1/observability/ingestion/db-pressure`

WU3's deadlock/lock-wait retry instrumentation: event counters via
`Metrics::get()` and latency percentiles via `Metrics::percentile()`.

```json
"data": {
  "counters": { "attempted": 2, "succeeded": 1, "exhausted": 0, "non_retryable": 0 },
  "delay_ms": { "p50": {"value": 100.0, "sample_count": 2}, "p95": {...}, "p99": {...} },
  "operation_ms": {...},
  "total_recovery_ms": {...},
  "note": "Retry-observed DB pressure only (WU3): zero retries does not mean zero lock contention."
}
```

This measures **retry-observed** pressure only — contention that never
raised one of the three matched deadlock/lock-wait phrases is invisible
here (see `DeadlockRetryService`'s own class doc-comment). Zero counters
does not prove zero DB contention.

**Drill/incident use**: rising `attempted`/`exhausted` counters alongside
climbing `total_recovery_ms` percentiles indicate the database is under
lock contention independent of ingestion queue depth — a different root
cause from backpressure/breaker conditions.

### 3.6 `GET /api/v1/observability/ingestion/skew`

Bounded top-N tenant/terminal "talkers" ranking for the current time window,
via `SkewRankingService::topTenants()`/`topTerminals()` (WU4). Accepts an
optional `?limit=` query parameter, but **the limit is clamped
server-side by the service itself** (`tsms.metrics.skew.max_top_n`) — this
controller does not apply a second, independent clamp, so the two limits
can never drift apart.

```json
"data": {
  "requested_limit": 10,
  "tenants": [{ "member": "101", "count": 42.0 }],
  "terminals": [{ "member": "9001", "count": 17.0 }]
}
```

`requested_limit` echoes the raw, unclamped input so an operator can tell
when their request was clamped down.

**Known limitation**: `SkewRankingService` swallows Redis failures
internally and returns an empty list rather than propagating an exception
(documented on the service: "must never affect ingestion admission or
behavior"). A Redis outage here is indistinguishable from a genuinely quiet
window — both show `status: "available"` with empty arrays.

**Drill/incident use**: identify which specific tenant(s)/terminal(s) are
driving an overall volume spike, to decide whether a WU5 tenant-throttle
override (`ingestion:tenant-throttle:set`) is warranted — see §4 below for
where that command itself is documented.

### 3.7 `GET /api/v1/observability/ingestion/rejections`

Fixed set of the six known `ingestion.rejected.*` counters (WU4/WU5).

```json
"data": {
  "reasons": {
    "payload_size": 0, "batch_size": 0, "backpressure": 3,
    "circuit_breaker": 0, "fairness": 1, "tenant_override": 0
  },
  "total": 4
}
```

**Drill/incident use**: the fastest way to answer "why are requests being
rejected right now" — compare relative counter growth to identify the
dominant rejection cause (backpressure vs. fairness vs. breaker vs. an
active WU5 tenant override) before drilling into that mechanism's own
endpoint above.

### 3.8 `GET /api/v1/observability/ingestion/percentiles`

Percentile (p50/p95/p99) reads via `Metrics::percentile()` (WU2) for every
metric name currently sampled via `Metrics::sample()` anywhere in this
codebase — as of this work unit, only WU3's `db.deadlock_retry.*` trio
(`delay_ms`, `operation_ms`, `total_recovery_ms`). WU4 added ingestion
rejection/queue/breaker instrumentation via `Metrics::incr()`/`timing()`
only, not `sample()`, so there is currently no ingestion-latency
distribution to report. This is intentional — an honest, if currently
narrow, list, not a placeholder for unimplemented metrics.

```json
"data": {
  "metrics": {
    "db.deadlock_retry.delay_ms": { "p50": {"value": null, "sample_count": 0}, "p95": {...}, "p99": {...} },
    "db.deadlock_retry.operation_ms": {...},
    "db.deadlock_retry.total_recovery_ms": {...}
  }
}
```

`value: null` (never `0`) means zero samples exist yet — distinguishing "no
data" from "zero latency" per `Metrics::percentile()`'s own contract.

**Known limitation**: like §3.6, `Metrics::percentile()` swallows its own
Redis failures and returns `{"value": null, "sample_count": 0}` rather than
propagating an exception — a Redis outage here is indistinguishable from
"no samples yet."

## 4. What This Document Does Not Cover

- Alert thresholds/definitions for any of the above — see WU8's
  `docs/OBSERVABILITY_ALERT_DEFINITIONS.md`.
- Circuit breaker/backpressure state-machine semantics and recovery
  procedures — see `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`.
- Shard-count topology changes — see `docs/SHARD_COUNT_CHANGE_RUNBOOK.md`.
- Setting/clearing a tenant throttle override — these endpoints are strictly
  read-only and cannot do this; use the WU5 Artisan commands
  (`ingestion:tenant-throttle:{set,status,clear}`), documented in WU9's
  tenant-throttling runbook.

## Unknown / Not Verifiable From This Repository

- Real production dashboard tooling (Grafana, etc.) that might consume
  these endpoints is not present in this repository — **unknown**.
